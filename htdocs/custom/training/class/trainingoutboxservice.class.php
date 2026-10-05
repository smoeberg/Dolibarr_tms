<?php
/**
 * Training Outbox Service
 * 
 * Async notification queue for sending emails and other notifications.
 * Features:
 * - File-based queue storage
 * - Idempotent retry logic
 * - Exponential backoff
 * - Comprehensive logging
 * - Status tracking
 */

require_once __DIR__.'/trainingstore.class.php';

final class TrainingOutboxService
{
    private TrainingStore $store;
    
    // Retry configuration
    private const MAX_ATTEMPTS = 5;
    private const BASE_DELAY = 60; // 1 minute in seconds
    private const MAX_DELAY = 3600; // 1 hour in seconds
    private const BACKOFF_MULTIPLIER = 2;
    
    // Status constants
    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';
    private const STATUS_SKIPPED = 'skipped';

    public function __construct(TrainingStore $store)
    {
        $this->store = $store;
    }

    // ========================================================================
    // QUEUE MANAGEMENT
    // ========================================================================

    /**
     * Add a message to the outbox queue.
     *
     * @param string $objectType Type of object (e.g., 'enrollment', 'payment')
     * @param int $objectId ID of the object
     * @param string $action Action type (e.g., 'confirmation', 'reminder')
     * @param string $recipientType Type of recipient ('contact', 'user', 'email')
     * @param int $recipientId ID of the recipient
     * @param string $recipientEmail Email address of the recipient
     * @param string $subject Email subject
     * @param string $bodyText Plain text body
     * @param string|null $bodyHtml HTML body (optional)
     * @param array $metadata Additional metadata
     * @param string|null $scheduledAt Scheduled time (YYYY-MM-DD HH:MM:SS)
     * @return int The outbox message ID
     */
    public function queueMessage(
        string $objectType,
        int $objectId,
        string $action,
        string $recipientType,
        int $recipientId,
        string $recipientEmail,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        array $metadata = array(),
        ?string $scheduledAt = null
    ): int {
        $this->store->access->requireDomain('checkout', 'write');
        
        $entity = $this->store->access->entity();
        $actor = $this->store->access->actor();
        $now = $this->store->now();
        
        $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_THROW_ON_ERROR) : null;
        $bodyHtml = $bodyHtml ?? null;
        
        $this->store->query(
            'INSERT INTO '.$this->store->table('outbox').
            ' (entity, object_type, fk_object, action, recipient_type, recipient_id, '.
            'recipient_email, subject, body_text, body_html, metadata_json, '.
            'status, attempts, scheduled_at, datec, fk_user_author, changed_at, fk_user_modifier) VALUES ('
            .$entity.', '
            .$this->store->text($objectType).', '.$objectId.', '
            .$this->store->text($action).', '
            .$this->store->text($recipientType).', '.$recipientId.', '
            .$this->store->text($recipientEmail).', '
            .$this->store->text($subject).', '
            .$this->store->text($bodyText).', '
            .($bodyHtml ? $this->store->text($bodyHtml) : 'NULL').', '
            .($metadataJson ? $this->store->text($metadataJson) : 'NULL').', '
            .$this->store->text(self::STATUS_PENDING).', 0, '
            .($scheduledAt ? $this->store->text($scheduledAt) : 'NULL').', '
            .$now.', '.$actor.', '.$now.', '.$actor.')'
        );
        
        $id = (int) $this->store->db->last_insert_id($this->store->table('outbox'));
        
        $this->logOutboxEvent($id, 'queued', array(
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action' => $action,
            'recipient_email' => $recipientEmail
        ));
        
        return $id;
    }

    /**
     * Get messages from the queue that are ready to be processed.
     *
     * @param int $limit Maximum number of messages to retrieve
     * @return array Array of outbox messages
     */
    public function getPendingMessages(int $limit = 10): array
    {
        $this->store->access->requireDomain('checkout', 'read');
        
        $entity = $this->store->access->entity();
        $now = $this->store->decisionTime();
        
        $rows = $this->store->rows(
            'SELECT * FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_PENDING).
            ' AND (scheduled_at IS NULL OR scheduled_at <= '.$this->store->text($now).')'.
            ' ORDER BY datec ASC, rowid ASC'.
            ' LIMIT '.$limit.' FOR UPDATE'
        );
        
        return $rows;
    }

    /**
     * Get messages that are scheduled for future processing.
     *
     * @param string $scheduledBefore Only get messages scheduled before this time
     * @return array Array of scheduled messages
     */
    public function getScheduledMessages(string $scheduledBefore): array
    {
        $this->store->access->requireDomain('checkout', 'read');
        
        $entity = $this->store->access->entity();
        
        $rows = $this->store->rows(
            'SELECT * FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_PENDING).
            ' AND scheduled_at IS NOT NULL'.
            ' AND scheduled_at > '.$this->store->text($scheduledBefore).
            ' ORDER BY scheduled_at ASC, rowid ASC'
        );
        
        return $rows;
    }

    /**
     * Get messages that have failed and can be retried.
     *
     * @param int $limit Maximum number of messages to retrieve
     * @return array Array of failed messages ready for retry
     */
    public function getRetryableMessages(int $limit = 10): array
    {
        $this->store->access->requireDomain('checkout', 'read');
        
        $entity = $this->store->access->entity();
        $now = $this->store->decisionTime();
        
        $rows = $this->store->rows(
            'SELECT * FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_FAILED).
            ' AND attempts < '.self::MAX_ATTEMPTS.
            ' AND (last_attempt IS NULL OR last_attempt <= '.$this->store->text($this->calculateNextRetryTime($now)).')'.
            ' ORDER BY datec ASC, rowid ASC'.
            ' LIMIT '.$limit.' FOR UPDATE'
        );
        
        return $rows;
    }

    /**
     * Calculate the next retry time based on current attempts.
     * Uses exponential backoff.
     *
     * @param string $now Current timestamp
     * @param int $attempts Number of attempts so far
     * @return string Next retry timestamp
     */
    private function calculateNextRetryTime(string $now, int $attempts = 0): string
    {
        $delay = min(
            self::MAX_DELAY,
            self::BASE_DELAY * pow(self::BACKOFF_MULTIPLIER, $attempts)
        );
        
        return gmdate('Y-m-d H:i:s', strtotime($now.' UTC') + $delay);
    }

    // ========================================================================
    // MESSAGE PROCESSING
    // ========================================================================

    /**
     * Process a single outbox message.
     *
     * @param object $message The outbox message row from database
     * @return array Result of processing
     */
    public function processMessage(object $message): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $messageId = (int) $message->rowid;
        
        try {
            // Mark as processing
            $this->updateMessageStatus($messageId, self::STATUS_PROCESSING);
            
            // Process based on action type
            $result = $this->processMessageByType($message);
            
            if ($result['success']) {
                $this->updateMessageStatus($messageId, self::STATUS_COMPLETED);
                $this->logOutboxEvent($messageId, 'completed', $result);
            } else {
                $this->updateMessageStatus(
                    $messageId,
                    self::STATUS_FAILED,
                    $result['error'] ?? 'Unknown error'
                );
                $this->logOutboxEvent($messageId, 'failed', $result);
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->updateMessageStatus($messageId, self::STATUS_FAILED, $e->getMessage());
            $this->logOutboxEvent($messageId, 'failed', array('error' => $e->getMessage()));
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Process a message based on its type.
     *
     * @param object $message The outbox message
     * @return array Processing result
     */
    private function processMessageByType(object $message): array
    {
        switch ($message->action) {
            case 'confirmation':
                return $this->processConfirmationEmail($message);
            
            case 'reminder':
                return $this->processReminderEmail($message);
            
            case 'notification':
                return $this->processNotificationEmail($message);
            
            default:
                return $this->processGenericEmail($message);
        }
    }

    /**
     * Process a confirmation email (for enrollments).
     */
    private function processConfirmationEmail(object $message): array
    {
        global $conf, $langs;
        
        // Load the email template
        $subject = $this->getEmailSubject($message->object_type, $message->action);
        $bodyText = $this->getEmailBody($message->object_type, $message->action, $message->fk_object, 'text');
        $bodyHtml = $this->getEmailBody($message->object_type, $message->action, $message->fk_object, 'html');
        
        // Override with message-specific content if provided
        if (!empty($message->subject)) {
            $subject = $message->subject;
        }
        if (!empty($message->body_text)) {
            $bodyText = $message->body_text;
        }
        if (!empty($message->body_html)) {
            $bodyHtml = $message->body_html;
        }
        
        // Send the email
        return $this->sendEmail(
            $message->recipient_email,
            $subject,
            $bodyText,
            $bodyHtml
        );
    }

    /**
     * Process a reminder email.
     */
    private function processReminderEmail(object $message): array
    {
        return $this->sendEmail(
            $message->recipient_email,
            $message->subject,
            $message->body_text,
            $message->body_html
        );
    }

    /**
     * Process a generic notification email.
     */
    private function processNotificationEmail(object $message): array
    {
        return $this->sendEmail(
            $message->recipient_email,
            $message->subject,
            $message->body_text,
            $message->body_html
        );
    }

    /**
     * Process a generic email message.
     */
    private function processGenericEmail(object $message): array
    {
        return $this->sendEmail(
            $message->recipient_email,
            $message->subject,
            $message->body_text,
            $message->body_html
        );
    }

    /**
     * Send an email using Dolibarr's email system.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $bodyText Plain text body
     * @param string|null $bodyHtml HTML body
     * @return array Result with success status and error message
     */
    private function sendEmail(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): array
    {
        global $conf, $langs, $db;
        
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array('success' => false, 'error' => 'Invalid email address');
        }
        
        // Use Dolibarr's email sending
        require_once DOL_DOCUMENT_ROOT.'/core/class/mailing.class.php';
        
        $mail = new Mailing($db);
        $mail->from = $conf->global->MAIN_MAIL_FROM ?? $conf->email_from;
        $mail->fromname = $conf->global->MAIN_MAIL_FROM_NAME ?? $conf->name;
        $mail->to = $to;
        $mail->subject = $subject;
        $mail->body = $bodyText;
        $mail->bodyhtml = $bodyHtml;
        
        // Set reply-to
        if (!empty($conf->global->MAIN_MAIL_REPLY_TO)) {
            $mail->replyto = $conf->global->MAIN_MAIL_REPLY_TO;
        }
        
        // Send the email
        $result = $mail->send();
        
        if ($result > 0) {
            return array('success' => true, 'message_id' => $result);
        } else {
            return array('success' => false, 'error' => $mail->error);
        }
    }

    // ========================================================================
    // EMAIL TEMPLATES
    // ========================================================================

    /**
     * Get email subject for a specific object type and action.
     *
     * @param string $objectType Type of object
     * @param string $action Action type
     * @return string Email subject
     */
    private function getEmailSubject(string $objectType, string $action): string
    {
        global $langs;
        
        $langs->loadLangs(array('training@training'));
        
        $key = 'TrainingEmail'.ucfirst($objectType).ucfirst($action).'Subject';
        $subject = $langs->trans($key);
        
        // Fallback to default subject
        if ($subject === $key) {
            return 'Training Notification';
        }
        
        return $subject;
    }

    /**
     * Get email body for a specific object type and action.
     *
     * @param string $objectType Type of object
     * @param string $action Action type
     * @param int $objectId ID of the object
     * @param string $format 'text' or 'html'
     * @return string Email body
     */
    private function getEmailBody(string $objectType, string $action, int $objectId, string $format = 'text'): string
    {
        global $langs, $db;
        
        $langs->loadLangs(array('training@training'));
        
        // Try to get template from language files
        $key = 'TrainingEmail'.ucfirst($objectType).ucfirst($action).ucfirst($format);
        $body = $langs->trans($key);
        
        // If we have a template, use it
        if ($body !== $key) {
            // Replace placeholders with actual data
            $body = $this->replacePlaceholders($body, $objectType, $objectId);
            return $body;
        }
        
        // Fallback to default template based on object type
        return $this->getDefaultEmailBody($objectType, $action, $objectId, $format);
    }

    /**
     * Replace placeholders in email template with actual data.
     *
     * @param string $template Template with placeholders
     * @param string $objectType Type of object
     * @param int $objectId ID of the object
     * @return string Template with placeholders replaced
     */
    private function replacePlaceholders(string $template, string $objectType, int $objectId): string
    {
        global $db;
        
        $replacements = array();
        
        switch ($objectType) {
            case 'enrollment':
                $replacements = $this->getEnrollmentPlaceholders($objectId);
                break;
            case 'checkout_session':
                $replacements = $this->getCheckoutSessionPlaceholders($objectId);
                break;
            case 'payment':
                $replacements = $this->getPaymentPlaceholders($objectId);
                break;
        }
        
        // Replace all placeholders
        foreach ($replacements as $placeholder => $value) {
            $template = str_replace('{'.$placeholder.'}', $value, $template);
        }
        
        return $template;
    }

    /**
     * Get placeholders for enrollment emails.
     */
    private function getEnrollmentPlaceholders(int $enrollmentId): array
    {
        global $db;
        
        $sql = 'SELECT e.*, l.fk_socpeople, s.ref as session_ref, s.label as session_label';
        $sql .= ' FROM '.$db->prefix().'training_enrollment e';
        $sql .= ' JOIN '.$db->prefix().'training_learner l ON l.rowid=e.fk_learner AND l.entity=e.entity';
        $sql .= ' JOIN '.$db->prefix().'training_session s ON s.rowid=e.fk_session AND s.entity=e.entity';
        $sql .= ' WHERE e.rowid='.$enrollmentId.' AND e.entity='.$this->store->access->entity();
        
        $result = $db->query($sql);
        if (!$result || !$db->fetch_object($result)) {
            return array();
        }
        
        $row = $db->fetch_object($result);
        
        // Get contact info
        $contact = $this->getContactInfo((int) $row->fk_socpeople);
        
        return array(
            'enrollment_id' => $enrollmentId,
            'session_ref' => $row->session_ref,
            'session_label' => $row->session_label,
            'participant_name' => $contact['firstname'].' '.$contact['lastname'],
            'participant_email' => $contact['email'] ?? '',
            'status' => $row->status,
            'date' => $row->datec
        );
    }

    /**
     * Get placeholders for checkout session emails.
     */
    private function getCheckoutSessionPlaceholders(int $checkoutSessionId): array
    {
        global $db;
        
        $sql = 'SELECT * FROM '.$db->prefix().'training_checkout_session';
        $sql .= ' WHERE rowid='.$checkoutSessionId.' AND entity='.$this->store->access->entity();
        
        $result = $db->query($sql);
        if (!$result || !$db->fetch_object($result)) {
            return array();
        }
        
        $row = $db->fetch_object($result);
        
        return array(
            'checkout_session_id' => $checkoutSessionId,
            'session_ref' => $row->session_ref,
            'total_amount' => price($row->total_amount_ttc, 1, '', 1, 0, 0, $row->currency),
            'currency' => $row->currency,
            'status' => $row->status
        );
    }

    /**
     * Get placeholders for payment emails.
     */
    private function getPaymentPlaceholders(int $paymentId): array
    {
        global $db;
        
        $sql = 'SELECT * FROM '.$db->prefix().'training_checkout_payment';
        $sql .= ' WHERE rowid='.$paymentId.' AND entity='.$this->store->access->entity();
        
        $result = $db->query($sql);
        if (!$result || !$db->fetch_object($result)) {
            return array();
        }
        
        $row = $db->fetch_object($result);
        
        return array(
            'payment_id' => $paymentId,
            'amount' => price($row->amount_ttc, 1, '', 1, 0, 0, $row->currency),
            'currency' => $row->currency,
            'payment_status' => $row->payment_status,
            'payment_method' => $row->payment_method_type ?? 'unknown'
        );
    }

    /**
     * Get default email body for a specific object type.
     */
    private function getDefaultEmailBody(string $objectType, string $action, int $objectId, string $format): string
    {
        global $langs;
        
        $langs->loadLangs(array('training@training'));
        
        switch ($objectType) {
            case 'enrollment':
                if ($action === 'confirmation') {
                    if ($format === 'html') {
                        return $this->getDefaultEnrollmentConfirmationHtml($objectId);
                    }
                    return $this->getDefaultEnrollmentConfirmationText($objectId);
                }
                break;
            
            case 'checkout_session':
                if ($action === 'confirmation') {
                    if ($format === 'html') {
                        return $this->getDefaultCheckoutConfirmationHtml($objectId);
                    }
                    return $this->getDefaultCheckoutConfirmationText($objectId);
                }
                break;
        }
        
        // Generic fallback
        return $format === 'html' ? '<p>You have a new notification.</p>' : 'You have a new notification.';
    }

    /**
     * Get default enrollment confirmation email (text).
     */
    private function getDefaultEnrollmentConfirmationText(int $enrollmentId): string
    {
        $placeholders = $this->getEnrollmentPlaceholders($enrollmentId);
        
        $body = "Thank you for your booking!\n\n";
        $body .= "Session: {$placeholders['session_ref']} - {$placeholders['session_label']}\n";
        $body .= "Participant: {$placeholders['participant_name']}\n";
        $body .= "Status: {$placeholders['status']}\n";
        $body .= "Date: {$placeholders['date']}\n\n";
        $body .= "You will receive further details shortly.\n";
        
        return $body;
    }

    /**
     * Get default enrollment confirmation email (HTML).
     */
    private function getDefaultEnrollmentConfirmationHtml(int $enrollmentId): string
    {
        $placeholders = $this->getEnrollmentPlaceholders($enrollmentId);
        
        $body = '<html><body>';
        $body .= '<h1>Thank you for your booking!</h1>';
        $body .= '<p>Your enrollment has been confirmed.</p>';
        $body .= '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
        $body .= '<tr><th>Session</th><td>'.$placeholders['session_ref'].' - '.$placeholders['session_label'].'</td></tr>';
        $body .= '<tr><th>Participant</th><td>'.$placeholders['participant_name'].'</td></tr>';
        $body .= '<tr><th>Status</th><td>'.$placeholders['status'].'</td></tr>';
        $body .= '<tr><th>Date</th><td>'.$placeholders['date'].'</td></tr>';
        $body .= '</table>';
        $body .= '<p>You will receive further details shortly.</p>';
        $body .= '</body></html>';
        
        return $body;
    }

    /**
     * Get default checkout confirmation email (text).
     */
    private function getDefaultCheckoutConfirmationText(int $checkoutSessionId): string
    {
        $placeholders = $this->getCheckoutSessionPlaceholders($checkoutSessionId);
        
        $body = "Thank you for your payment!\n\n";
        $body .= "Checkout Session: CHK-{$placeholders['checkout_session_id']}\n";
        $body .= "Session: {$placeholders['session_ref']}\n";
        $body .= "Total Amount: {$placeholders['total_amount']}\n";
        $body .= "Status: {$placeholders['status']}\n\n";
        $body .= "Your payment has been processed successfully.\n";
        
        return $body;
    }

    /**
     * Get default checkout confirmation email (HTML).
     */
    private function getDefaultCheckoutConfirmationHtml(int $checkoutSessionId): string
    {
        $placeholders = $this->getCheckoutSessionPlaceholders($checkoutSessionId);
        
        $body = '<html><body>';
        $body .= '<h1>Thank you for your payment!</h1>';
        $body .= '<p>Your payment has been processed successfully.</p>';
        $body .= '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
        $body .= '<tr><th>Checkout Session</th><td>CHK-'.$placeholders['checkout_session_id'].'</td></tr>';
        $body .= '<tr><th>Session</th><td>'.$placeholders['session_ref'].'</td></tr>';
        $body .= '<tr><th>Total Amount</th><td>'.$placeholders['total_amount'].'</td></tr>';
        $body .= '<tr><th>Status</th><td>'.$placeholders['status'].'</td></tr>';
        $body .= '</table>';
        $body .= '</body></html>';
        
        return $body;
    }

    /**
     * Get contact info by ID.
     */
    private function getContactInfo(int $contactId): array
    {
        global $db;
        
        if ($contactId <= 0) {
            return array('firstname' => '', 'lastname' => '', 'email' => '');
        }
        
        $sql = 'SELECT firstname, lastname, email FROM '.$db->prefix().'socpeople';
        $sql .= ' WHERE rowid='.$contactId.' AND entity IN (SELECT entity FROM '.$db->prefix().'entity WHERE active=1)';
        
        $result = $db->query($sql);
        if (!$result || !$db->fetch_object($result)) {
            return array('firstname' => '', 'lastname' => '', 'email' => '');
        }
        
        $row = $db->fetch_object($result);
        return array(
            'firstname' => $row->firstname ?? '',
            'lastname' => $row->lastname ?? '',
            'email' => $row->email ?? ''
        );
    }

    // ========================================================================
    // STATUS UPDATES
    // ========================================================================

    /**
     * Update the status of an outbox message.
     *
     * @param int $messageId ID of the outbox message
     * @param string $status New status
     * @param string|null $error Error message (for failed status)
     */
    public function updateMessageStatus(int $messageId, string $status, ?string $error = null): void
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $validStatuses = array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED
        );
        
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException('TrainingInvalidStatus');
        }
        
        $now = $this->store->now();
        
        $this->store->query(
            'UPDATE '.$this->store->table('outbox').
            ' SET status='.$this->store->text($status).
            ', attempts=attempts+1'.
            ', last_attempt='.$now.
            ', last_error='.($error ? $this->store->text($error) : 'NULL').
            ', changed_at='.$now.
            ', fk_user_modifier='.$this->store->access->actor().
            ' WHERE rowid='.$messageId.' AND entity='.$this->store->access->entity()
        );
    }

    /**
     * Mark a message as skipped (will not be retried).
     *
     * @param int $messageId ID of the outbox message
     * @param string $reason Reason for skipping
     */
    public function skipMessage(int $messageId, string $reason): void
    {
        $this->updateMessageStatus($messageId, self::STATUS_SKIPPED);
        $this->logOutboxEvent($messageId, 'skipped', array('reason' => $reason));
    }

    // ========================================================================
    // BATCH PROCESSING
    // ========================================================================

    /**
     * Process all pending and retryable messages.
     *
     * @param int $batchSize Maximum number of messages to process
     * @return array Processing statistics
     */
    public function processBatch(int $batchSize = 20): array
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $stats = array(
            'total' => 0,
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'skipped' => 0
        );
        
        // Process pending messages
        $pendingMessages = $this->getPendingMessages($batchSize);
        foreach ($pendingMessages as $message) {
            $stats['total']++;
            $result = $this->processMessage($message);
            $stats['processed']++;
            if ($result['success']) {
                $stats['completed']++;
            } else {
                $stats['failed']++;
            }
        }
        
        // Process retryable messages (if we have room)
        $remaining = $batchSize - $stats['processed'];
        if ($remaining > 0) {
            $retryableMessages = $this->getRetryableMessages($remaining);
            foreach ($retryableMessages as $message) {
                $stats['total']++;
                $result = $this->processMessage($message);
                $stats['processed']++;
                if ($result['success']) {
                    $stats['completed']++;
                } else {
                    $stats['failed']++;
                }
            }
        }
        
        return $stats;
    }

    // ========================================================================
    // STATISTICS & MONITORING
    // ========================================================================

    /**
     * Get outbox statistics.
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        $this->store->access->requireDomain('checkout', 'read');
        
        $entity = $this->store->access->entity();
        
        $stats = array();
        
        // Get counts by status
        $statuses = array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED
        );
        
        foreach ($statuses as $status) {
            $rows = $this->store->rows(
                'SELECT COUNT(*) as count FROM '.$this->store->table('outbox').
                ' WHERE entity='.$entity.
                ' AND status='.$this->store->text($status)
            );
            $stats[$status] = $rows ? (int) $rows[0]->count : 0;
        }
        
        // Get oldest pending message
        $rows = $this->store->rows(
            'SELECT datec FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_PENDING).
            ' ORDER BY datec ASC LIMIT 1'
        );
        $stats['oldest_pending'] = $rows ? $rows[0]->datec : null;
        
        // Get failed messages by error type
        $rows = $this->store->rows(
            'SELECT last_error, COUNT(*) as count FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_FAILED).
            ' GROUP BY last_error ORDER BY count DESC LIMIT 5'
        );
        $stats['top_errors'] = array_map(function($row) {
            return array('error' => $row->last_error, 'count' => (int) $row->count);
        }, $rows);
        
        return $stats;
    }

    /**
     * Get recent outbox activity.
     *
     * @param int $limit Maximum number of entries
     * @return array Recent activity
     */
    public function getRecentActivity(int $limit = 50): array
    {
        $this->store->access->requireDomain('checkout', 'read');
        
        $entity = $this->store->access->entity();
        
        return $this->store->rows(
            'SELECT * FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' ORDER BY datec DESC, rowid DESC LIMIT '.$limit
        );
    }

    // ========================================================================
    // LOGGING
    // ========================================================================

    /**
     * Log an outbox event for audit purposes.
     *
     * @param int $messageId ID of the outbox message
     * @param string $action Action performed
     * @param array $metadata Additional metadata
     */
    private function logOutboxEvent(int $messageId, string $action, array $metadata): void
    {
        $this->store->audit('outbox', $messageId, $action, $metadata);
    }

    // ========================================================================
    // CLEANUP
    // ========================================================================

    /**
     * Clean up old completed messages.
     *
     * @param int $days Number of days to keep
     * @return int Number of messages deleted
     */
    public function cleanupOldMessages(int $days = 30): int
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $entity = $this->store->access->entity();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        
        // Delete completed messages older than cutoff
        $this->store->query(
            'DELETE FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_COMPLETED).
            ' AND datec < '.$this->store->text($cutoff)
        );
        
        return (int) $this->store->db->affected_rows();
    }

    /**
     * Clean up old failed messages that have exceeded max attempts.
     *
     * @param int $days Number of days to keep
     * @return int Number of messages deleted
     */
    public function cleanupOldFailedMessages(int $days = 7): int
    {
        $this->store->access->requireDomain('checkout', 'write');
        
        $entity = $this->store->access->entity();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        
        // Delete failed messages older than cutoff with max attempts
        $this->store->query(
            'DELETE FROM '.$this->store->table('outbox').
            ' WHERE entity='.$entity.
            ' AND status='.$this->store->text(self::STATUS_FAILED).
            ' AND attempts >= '.self::MAX_ATTEMPTS.
            ' AND datec < '.$this->store->text($cutoff)
        );
        
        return (int) $this->store->db->affected_rows();
    }
}
