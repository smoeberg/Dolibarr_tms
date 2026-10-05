<?php
/** Native Dolibarr projections. Never write directly to Dolibarr-owned tables. */
interface TrainingDolibarrGateway
{
    public function project(int $id);
    public function createProject(string $ref, string $title, string $description, ?DateTimeImmutable $start, ?DateTimeImmutable $end);
    public function updateProject($object, string $title, string $description, ?DateTimeImmutable $start, ?DateTimeImmutable $end): void;
    public function action(int $id);
    public function createAction(string $label, DateTimeImmutable $start, DateTimeImmutable $end, int $projectId);
    public function updateAction($object, string $label, DateTimeImmutable $start, DateTimeImmutable $end, int $projectId): void;
}

final class TrainingDolibarrAdapter implements TrainingDolibarrGateway
{
    private $db;
    private $user;
    private int $entity;

    public function __construct($db, $user, int $entity)
    {
        $this->db = $db; $this->user = $user; $this->entity = $entity;
        if ((int) $GLOBALS['conf']->entity !== $entity) {
            throw new RuntimeException('TrainingEntityContextMismatch');
        }
    }

    public function project(int $id)
    {
        require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
        $object = new Project($this->db);
        if ($object->fetch($id) <= 0 || (int) $object->entity !== $this->entity) {
            throw new RuntimeException('TrainingNativeProjectNotFound');
        }
        return $object;
    }

    public function createProject(string $ref, string $title, string $description, ?DateTimeImmutable $start, ?DateTimeImmutable $end)
    {
        require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
        $object = new Project($this->db);
        $object->ref = $ref;
        $object->title = $title;
        $object->description = $description;
        $object->entity = $this->entity;
        $object->status = Project::STATUS_DRAFT;
        $object->public = 0;
        $object->usage_opportunity = 0;
        $object->usage_task = 0;
        $object->usage_bill_time = 0;
        $object->usage_organize_event = 0;
        $object->date_start = $start ? $start->setTimezone(new DateTimeZone('UTC'))->getTimestamp() : '';
        $object->date_end = $end ? $end->setTimezone(new DateTimeZone('UTC'))->getTimestamp() : '';
        $result = $object->create($this->user);
        if ($result <= 0) { throw new RuntimeException('TrainingNativeProjectCreateFailed'); }
        return $object;
    }

    public function updateProject($object, string $title, string $description, ?DateTimeImmutable $start, ?DateTimeImmutable $end): void
    {
        $object->title = $title;
        $object->description = $description;
        $object->entity = $this->entity;
        $object->date_start = $start ? $start->setTimezone(new DateTimeZone('UTC'))->getTimestamp() : '';
        $object->date_end = $end ? $end->setTimezone(new DateTimeZone('UTC'))->getTimestamp() : '';
        if ($object->update($this->user) <= 0) { throw new RuntimeException('TrainingNativeProjectUpdateFailed'); }
    }

    public function action(int $id)
    {
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
        $object = new ActionComm($this->db);
        if ($object->fetch($id) <= 0 || (int) $object->entity !== $this->entity) {
            throw new RuntimeException('TrainingNativeActionNotFound');
        }
        return $object;
    }

    public function createAction(string $label, DateTimeImmutable $start, DateTimeImmutable $end, int $projectId)
    {
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
        $object = new ActionComm($this->db);
        $object->type_code = 'AC_OTH';
        $object->label = $label;
        $object->datep = $start->getTimestamp();
        $object->datef = $end->getTimestamp();
        $object->fk_project = $projectId;
        $object->fk_element = $projectId;
        $object->elementid = $projectId;
        $object->elementtype = 'project';
        $object->userownerid = (int) $this->user->id;
        $object->userassigned = array((int) $this->user->id => array('id'=>(int) $this->user->id, 'transparency'=>1));
        $object->percentage = 0;
        $object->priority = 0;
        $object->fulldayevent = 0;
        $object->transparency = 1;
        $result = $object->create($this->user);
        if ($result <= 0) { throw new RuntimeException('TrainingNativeActionCreateFailed'); }
        return $object;
    }

    public function updateAction($object, string $label, DateTimeImmutable $start, DateTimeImmutable $end, int $projectId): void
    {
        $object->label = $label;
        $object->datep = $start->getTimestamp();
        $object->datef = $end->getTimestamp();
        $object->fk_project = $projectId;
        $object->fk_element = $projectId;
        $object->elementid = $projectId;
        $object->elementtype = 'project';
        if ($object->update($this->user) <= 0) { throw new RuntimeException('TrainingNativeActionUpdateFailed'); }
    }
}