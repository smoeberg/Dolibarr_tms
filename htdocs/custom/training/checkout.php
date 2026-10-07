<?php
/**
 * Training Checkout Page
 * 
 * Flow:
 * 1. User selects a session from the catalog
 * 2. User enters participant details
 * 3. Seat hold is created (15 minutes)
 * 4. Native Dolibarr invoice is created
 * 5. User pays through Dolibarr's native payment flow
 * 6. TMS observes the persisted native Paiement before confirmation
 */

require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingcheckoutservice.class.php';

$access = trainingAccess();
$store = new TrainingStore($db, $access);

$checkout = new TrainingCheckoutService($store);

// ========================================================================
// STEP 1: SESSION SELECTION
// ========================================================================

$sessionId = GETPOSTINT('session_id');
$action = GETPOST('action', 'aZ09');

// If no session selected, show session selection
if (!$sessionId && $action !== 'selectSession') {
    showSessionSelection($access, $store);
    exit;
}

// ========================================================================
// STEP 2: PARTICIPANT FORM (if session selected but no participants submitted)
// ========================================================================

$participants = GETPOST('participants', 'array');

if ($sessionId && $action !== 'submitParticipants' && $action !== 'createPayment') {
    showParticipantForm($access, $store, $sessionId);
    exit;
}

// ========================================================================
// STEP 3: CREATE SEAT HOLD AND PAYMENT INTENT
// ========================================================================

if ($action === 'submitParticipants' && $sessionId) {
    try {
        $participantData = validateParticipants($participants);
        
        if (empty($participantData)) {
            throw new RuntimeException('TrainingCheckoutAtLeastOneParticipant');
        }
        
        // Create seat hold
        $contactIds = array_map(function($p) { return (int) $p['contact_id']; }, $participantData);
        $seatHold = $checkout->createSeatHold($sessionId, $contactIds, 15);
        
        // Store seat hold in session for next step
        $_SESSION['training_checkout'] = array(
            'session_id' => $sessionId,
            'hold_id' => $seatHold['hold_id'],
            'reservation_key' => $seatHold['reservation_key'],
            'participants' => $participantData,
            'expires_at' => $seatHold['expires_at']
        );
        
        // Redirect to payment step
        header('Location: '.dol_buildpath('/training/checkout.php', 1).'?session_id='.$sessionId.'&hold_id='.$seatHold['hold_id'].'&action=createPayment');
        exit;
    } catch (Throwable $e) {
        $error = trainingError($e);
        showParticipantForm($access, $store, $sessionId, $error);
        exit;
    }
}

// ========================================================================
// STEP 4: CREATE NATIVE DOLIBARR PAYMENT
// ========================================================================

if ($action === 'createPayment' && $sessionId) {
    $holdId = GETPOSTINT('hold_id');
    if (!$holdId || empty($_SESSION['training_checkout'])) {
        header('Location: '.dol_buildpath('/training/checkout.php', 1)); exit;
    }
    try {
        $checkoutData = $_SESSION['training_checkout'];
        $result = $checkout->createNativeCheckoutSession(
            $sessionId,
            $checkoutData['participants'],
            $checkoutData['reservation_key'],
            $holdId
        );
        unset($_SESSION['training_checkout']);
        header('Location: '.$result['payment_url']); exit;
    } catch (Throwable $e) {
        unset($_SESSION['training_checkout']);
        $error = trainingError($e);
        llxHeader('', $langs->trans('TrainingCheckout'));
        print '<div class="error">'.$error.'</div>';
        print '<p><a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingBackToCheckout').'</a></p>';
        llxFooter(); $db->close(); exit;
    }
}

// ========================================================================
// HELPER FUNCTIONS
// ========================================================================

/**
 * Show session selection page.
 */
function showSessionSelection(TrainingAccess $access, TrainingStore $store): void
{
    global $langs, $db, $user;
    
    llxHeader('', $langs->trans('TrainingCheckout'));
    
    print '<h1>'.$langs->trans('TrainingSelectSession').'</h1>';
    print '<p>'.$langs->trans('TrainingSelectSessionDesc').'</p>';
    
    // Get available sessions from API
    try {
        $sessions = getAvailableSessions($store);
        
        if (empty($sessions)) {
            print '<div class="info">'.$langs->trans('TrainingNoSessionsAvailable').'</div>';
        } else {
            print '<table class="liste centpercent">';
            print '<tr class="liste_titre">';
            print '<th>'.$langs->trans('Ref').'</th>';
            print '<th>'.$langs->trans('Label').'</th>';
            print '<th>'.$langs->trans('TrainingCapacity').'</th>';
            print '<th>'.$langs->trans('TrainingAvailableSeats').'</th>';
            print '<th>'.$langs->trans('TrainingPrice').'</th>';
            print '<th>&nbsp;</th>';
            print '</tr>';
            
            foreach ($sessions as $session) {
                print '<tr>';
                print '<td>'.trainingEscape($session['ref']).'</td>';
                print '<td>'.trainingEscape($session['label']).'</td>';
                print '<td>'.(int) $session['capacity'].'</td>';
                print '<td>'.(int) $session['available_seats'].'</td>';
                print '<td>'.price($session['price_ttc'] ?? 0, 1, '', 1, 0, 0, $session['currency'] ?? 'DKK').'</td>';
                print '<td><a href="'.dol_buildpath('/training/checkout.php', 1).'?session_id='.(int) $session['session_id'].'" class="button">'.$langs->trans('TrainingSelect').'</a></td>';
                print '</tr>';
            }
            
            print '</table>';
        }
    } catch (Throwable $e) {
        print '<div class="error">'.trainingError($e).'</div>';
    }
    
    llxFooter();
    $db->close();
}

/**
 * Get available sessions for selection.
 */
function getAvailableSessions(TrainingStore $store): array
{
    global $db;
    
    $sessions = array();
    
    $sql = 'SELECT s.rowid, s.ref, s.label, s.capacity, s.timezone';
    $sql .= ' FROM '.$db->prefix().'training_session s';
    $sql .= ' JOIN '.$db->prefix().'training_course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity';
    $sql .= ' WHERE s.entity='.$store->access->entity().' AND v.status=\'published\' AND s.status=\'open\'';
    $sql .= ' ORDER BY s.rowid DESC';
    
    $result = $db->query($sql);
    if (!$result) {
        return $sessions;
    }
    
    while ($row = $db->fetch_object($result)) {
        $sessionId = (int) $row->rowid;
        
        // Get available seats
        try {
            $capacity = $store->capacity($sessionId);
            $available = (int) $row->capacity - $capacity['occupied'];
        } catch (Throwable $e) {
            $available = 0;
        }
        
        // Get price
        try {
            $priceInfo = getSessionPriceForStore($store, $sessionId);
        } catch (Throwable $e) {
            $priceInfo = array('price_ttc' => 0, 'currency' => 'DKK');
        }
        
        if ($available > 0) {
            $sessions[] = array(
                'session_id' => $sessionId,
                'ref' => $row->ref,
                'label' => $row->label,
                'capacity' => (int) $row->capacity,
                'available_seats' => $available,
                'price_ttc' => $priceInfo['price_ttc'],
                'currency' => $priceInfo['currency']
            );
        }
    }
    
    return $sessions;
}

/**
 * Get price for a session (for store).
 */
function getSessionPriceForStore(TrainingStore $store, int $sessionId): array
{
    global $db;
    
    $sql = 'SELECT p.price, p.tva_tx, p.price_base_type as currency';
    $sql .= ' FROM '.$db->prefix().'training_session s';
    $sql .= ' JOIN '.$db->prefix().'training_course_version v ON v.rowid=s.fk_course_version AND v.entity=s.entity';
    $sql .= ' JOIN '.$db->prefix().'training_course_profile p ON p.rowid=v.fk_course_profile AND p.entity=v.entity';
    $sql .= ' JOIN '.$db->prefix().'product prod ON prod.rowid=p.fk_product';
    $sql .= ' WHERE s.rowid='.$sessionId.' AND s.entity='.$store->access->entity;
    
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Price not found');
    }
    $row = $db->fetch_object($result);
    if (!$row) {
        throw new RuntimeException('Price not found');
    }
    $price = (float) $row->price;
    $tvaTx = (float) $row->tva_tx;
    $currency = $row->currency ?: 'DKK';
    
    $priceTtc = $price * (1 + ($tvaTx / 100));
    
    return array(
        'price_ttc' => $priceTtc,
        'currency' => $currency
    );
}

/**
 * Show participant form.
 */
function showParticipantForm(TrainingAccess $access, TrainingStore $store, int $sessionId, string $error = ''): void
{
    global $langs, $db, $user;
    
    try {
        $session = $store->session($sessionId);
        $capacity = $store->capacity($sessionId);
        $available = (int) $session->capacity - $capacity['occupied'];
        
        if ($available <= 0) {
            llxHeader('', $langs->trans('TrainingCheckout'));
            print '<div class="error">'.$langs->trans('TrainingSessionFull').'</div>';
            print '<p><a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingBackToSelection').'</a></p>';
            llxFooter();
            $db->close();
            exit;
        }
        
        llxHeader('', $langs->trans('TrainingCheckout'));
        
        print '<h1>'.$langs->trans('TrainingCheckoutForSession', $session->ref).'</h1>';
        print '<p>'.$langs->trans('TrainingAvailableSeats', $available).'</p>';
        
        if ($error) {
            print '<div class="error">'.$error.'</div>';
        }
        
        // Get session price
        try {
            $priceInfo = getSessionPriceForStore($store, $sessionId);
            print '<p>'.$langs->trans('TrainingPricePerPerson', price($priceInfo['price_ttc'], 1, '', 1, 0, 0, $priceInfo['currency'])).'</p>';
        } catch (Throwable $e) {
            // Price not available
        }
        
        print '<form method="post" action="'.dol_buildpath('/training/checkout.php', 1).'" id="participantForm">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="session_id" value="'.$sessionId.'">';
        print '<input type="hidden" name="action" value="submitParticipants">';
        
        // Existing participants (if any)
        $existingParticipants = GETPOST('participants', 'array');
        $participantCount = max(1, count($existingParticipants ?? array()));
        
        print '<h2>'.$langs->trans('TrainingParticipants').'</h2>';
        print '<p>'.$langs->trans('TrainingAddParticipantsDesc').'</p>';
        
        // Participant fields
        $participantFields = array(
            'first_name' => 'FirstName',
            'last_name' => 'LastName',
            'email' => 'Email',
            'phone' => 'Phone'
        );
        
        print '<div id="participantsContainer">';
        for ($i = 0; $i < $participantCount; $i++) {
            printParticipantRow($i, $participantFields, $existingParticipants[$i] ?? array());
        }
        print '</div>';
        
        print '<button type="button" id="addParticipant" class="button">'.$langs->trans('TrainingAddAnotherParticipant').'</button>';
        print '<button type="submit" class="button">'.$langs->trans('TrainingContinueToPayment').'</button>';
        print '</form>';
        
        print '<p><a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingCancel').'</a></p>';
        
        // JavaScript for dynamic participant addition
        print '<script>';
        print 'document.getElementById("addParticipant").addEventListener("click", function() {';
        print '    var container = document.getElementById("participantsContainer");';
        print '    var count = container.children.length;';
        print '    var html = \'<div class="participantRow">';
        print '        <h3>Participant \' + (count + 1) + \'</h3>';
        
        foreach ($participantFields as $field => $label) {
            print '        <p><label>'.$langs->trans($label).' <input type="text" name="participants[\'+count+\']['.$field.']" required></label></p>';
        }
        
        print '        <button type="button" class="removeParticipant button" data-index="\' + count + \'">Remove</button>';
        print '    </div>\';';
        print '    container.innerHTML += html;';
        print '    addRemoveListeners();';
        print '});';
        
        print 'function addRemoveListeners() {';
        print '    document.querySelectorAll(".removeParticipant").forEach(function(btn) {';
        print '        btn.addEventListener("click", function() {';
        print '            var index = parseInt(this.getAttribute("data-index"));';
        print '            var container = document.getElementById("participantsContainer");';
        print '            if (container.children.length > 1) {';
        print '                container.removeChild(container.children[index]);';
        print '                renameParticipants();';
        print '            }';
        print '        });';
        print '    });';
        print '}';
        
        print 'function renameParticipants() {';
        print '    var container = document.getElementById("participantsContainer");';
        print '    var children = container.children;';
        print '    for (var i = 0; i < children.length; i++) {';
        print '        var inputs = children[i].querySelectorAll("input");';
        print '        inputs.forEach(function(input) {';
        print '            var name = input.name.replace(/\\d+/, i);';
        print '            input.name = name;';
        print '        });';
        print '        var h3 = children[i].querySelector("h3");';
        print '        if (h3) h3.textContent = "Participant " + (i + 1);';
        print '    }';
        print '}';
        
        print 'addRemoveListeners();';
        print '</script>';
        
        llxFooter();
        $db->close();
    } catch (Throwable $e) {
        llxHeader('', $langs->trans('TrainingCheckout'));
        print '<div class="error">'.trainingError($e).'</div>';
        print '<p><a href="'.dol_buildpath('/training/checkout.php', 1).'" class="button">'.$langs->trans('TrainingBackToSelection').'</a></p>';
        llxFooter();
        $db->close();
    }
}

/**
 * Print a participant row.
 */
function printParticipantRow(int $index, array $fields, array $values = array()): void
{
    global $langs;
    
    print '<div class="participantRow">';
    print '<h3>'.$langs->trans('TrainingParticipant').' '.($index + 1).'</h3>';
    
    foreach ($fields as $field => $label) {
        $value = $values[$field] ?? '';
        $type = $field === 'email' ? 'email' : 'text';
        print '<p><label>'.$langs->trans($label).' <input type="'.$type.'" name="participants['.$index.']['.$field.']" value="'.trainingEscape($value).'" required></label></p>';
    }
    
    if ($index > 0) {
        print '<button type="button" class="removeParticipant button" data-index="'.$index.'">'.$langs->trans('TrainingRemove').'</button>';
    }
    print '</div>';
}

/**
 * Validate and process participant data.
 */
function validateParticipants(array $participants): array
{
    $validated = array();
    
    if (empty($participants)) {
        throw new RuntimeException('TrainingCheckoutAtLeastOneParticipant');
    }
    
    foreach ($participants as $participant) {
        if (empty($participant['first_name']) || strlen($participant['first_name']) > 128) {
            throw new RuntimeException('TrainingCheckoutInvalidFirstName');
        }
        
        if (empty($participant['last_name']) || strlen($participant['last_name']) > 128) {
            throw new RuntimeException('TrainingCheckoutInvalidLastName');
        }
        
        if (!empty($participant['email']) && !filter_var($participant['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('TrainingCheckoutInvalidEmail');
        }
        
        // Find or create contact
        $contactId = findOrCreateContact($participant);
        
        $validated[] = array(
            'contact_id' => $contactId,
            'first_name' => trim($participant['first_name']),
            'last_name' => trim($participant['last_name']),
            'email' => trim($participant['email'] ?? ''),
            'phone' => trim($participant['phone'] ?? '')
        );
    }
    
    return $validated;
}

/**
 * Find or create a contact based on email/name.
 */
function findOrCreateContact(array $participant): int
{
    global $db;
    
    $email = trim($participant['email'] ?? '');
    $firstName = trim($participant['first_name']);
    $lastName = trim($participant['last_name']);
    
    // Try to find existing contact by email
    if (!empty($email)) {
        $sql = 'SELECT rowid FROM '.$db->prefix().'socpeople';
        $sql .= ' WHERE email='.$db->quote($email).' AND entity IN (SELECT entity FROM '.$db->prefix().'entity WHERE active=1)';
        
        $result = $db->query($sql);
        if ($result && $row = $db->fetch_object($result)) {
            return (int) $row->rowid;
        }
    }
    
    // Try to find by name
    $sql = 'SELECT rowid FROM '.$db->prefix().'socpeople';
    $sql .= ' WHERE firstname='.$db->quote($firstName).' AND lastname='.$db->quote($lastName);
    $sql .= ' AND entity IN (SELECT entity FROM '.$db->prefix().'entity WHERE active=1)';
    
    $result = $db->query($sql);
    if ($result && $row = $db->fetch_object($result)) {
        return (int) $row->rowid;
    }
    
    throw new RuntimeException('TrainingCheckoutExistingBillingContactRequired');
}

