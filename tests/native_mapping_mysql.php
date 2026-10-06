<?php
// Entity-scoped mapping and idempotency test. Native Dolibarr calls are replaced by a strict gateway double.
$nativeSchema=str_replace('llx_','tst_',file_get_contents(__DIR__.'/../htdocs/custom/training/sql/llx_training_znative.sql'));
$db->connection->multi_query($nativeSchema);
do { if ($result=$db->connection->store_result()) { $result->free(); } } while ($db->connection->more_results() && $db->connection->next_result());

final class NativeGatewayDouble implements TrainingDolibarrGateway
{
    public int $nextProject=700;
    public int $nextAction=900;
    public int $createdProjects=0;
    public int $updatedProjects=0;
    public int $createdActions=0;
    public int $updatedActions=0;
    private array $projects=array();
    private array $actions=array();

    public function project(int $id) {
        if (!isset($this->projects[$id])) { throw new RuntimeException('TrainingNativeProjectNotFound'); }
        return $this->projects[$id];
    }
    public function createProject(string $ref,string $title,string $description,?DateTimeImmutable $start,?DateTimeImmutable $end) {
        $o=(object)array('id'=>$this->nextProject++,'entity'=>2,'ref'=>$ref,'title'=>$title);
        $this->projects[$o->id]=$o; $this->createdProjects++; return $o;
    }
    public function updateProject($object,string $title,string $description,?DateTimeImmutable $start,?DateTimeImmutable $end): void {
        $object->title=$title; $this->updatedProjects++;
    }
    public function action(int $id) {
        if (!isset($this->actions[$id])) { throw new RuntimeException('TrainingNativeActionNotFound'); }
        return $this->actions[$id];
    }
    public function createAction(string $label,DateTimeImmutable $start,DateTimeImmutable $end,int $projectId) {
        $o=(object)array('id'=>$this->nextAction++,'entity'=>2,'label'=>$label,'project_id'=>$projectId);
        $this->actions[$o->id]=$o; $this->createdActions++; return $o;
    }
    public function updateAction($object,string $label,DateTimeImmutable $start,DateTimeImmutable $end,int $projectId): void {
        $object->label=$label; $object->project_id=$projectId; $this->updatedActions++;
    }
}

$native=new NativeGatewayDouble();
$integration=new TrainingDolibarrIntegrationService($store,$native);
$integrationSession=$scheduling->create($first,'NATIVE-MAP-01','Native mapping session',2,'Europe/Copenhagen');
$scheduling->replaceSlots($integrationSession,array(
    array('start'=>'2026-10-20T09:00:00+02:00','end'=>'2026-10-20T16:00:00+02:00'),
    array('start'=>'2026-10-21T09:00:00+02:00','end'=>'2026-10-21T16:00:00+02:00')
));
$scheduling->changeStatus($integrationSession,'open');
$project=$integration->syncSession($integrationSession);
verify($native->createdProjects===1,'first session sync creates one native project');
verify($integration->syncSession($integrationSession)===$project,'repeated session sync reuses mapped project');
verify($native->createdProjects===1 && $native->updatedProjects===1,'repeated session sync updates rather than duplicates');

$slotRows=$db->query('SELECT rowid FROM tst_training_session_slot WHERE entity=2 AND fk_session='.$integrationSession.' ORDER BY position');
$slot1=(int)$db->fetch_object($slotRows)->rowid;
$action=$integration->syncSlot($integrationSession,$slot1);
verify($native->createdActions===1,'first slot sync creates one native agenda event');
verify($integration->syncSlot($integrationSession,$slot1)===$action,'repeated slot sync reuses mapped agenda event');
verify($native->createdActions===1 && $native->updatedActions===1,'repeated slot sync updates rather than duplicates');
$agenda=$integration->syncSessionAgenda($integrationSession);
verify(count($agenda['actioncomm_ids'])===2,'whole-session agenda sync covers every slot');
verify($native->createdActions===2,'whole-session agenda sync creates the remaining slot event');
$integration->syncSessionAgenda($integrationSession);
verify($native->createdActions===2 && $native->updatedActions===4,'repeated whole-session agenda sync remains idempotent');

$maps=$db->count('training_native_link');
verify($maps===2,'session and slot each have exactly one mapping');
$row=$db->query("SELECT identity_key FROM tst_training_native_link WHERE source_type='session' AND fk_source=".$integrationSession)->fetch_object();
verify($row->identity_key==='training:session:2:'.$integrationSession.':project','mapping identity is deterministic and entity-scoped');

$foreignSession=$scheduling->create($first,'NATIVE-MAP-02','Second entity check',1,'Europe/Copenhagen');
verify($integration->syncSession($foreignSession)>0,'same service can sync another TMS object without cross-entity collision');
verify($db->count('training_native_link')===3,'each TMS source gets its own native mapping');

echo 'Native Dolibarr mapping MySQL tests passed.'.PHP_EOL;
