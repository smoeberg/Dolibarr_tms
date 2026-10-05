<?php
require_once __DIR__.'/trainingschedulingservice.class.php';
require_once __DIR__.'/trainingdolibarradapter.class.php';

final class TrainingDolibarrIntegrationService
{
    private TrainingStore $s;
    private TrainingDolibarrAdapter $native;

    public function __construct(TrainingStore $store, ?TrainingDolibarrAdapter $native = null)
    {
        $this->s = $store;
        $this->native = $native ?? new TrainingDolibarrAdapter($store->db, $this->actorUser(), $store->access->entity());
    }

    private function actorUser()
    {
        if (method_exists($this->s->access, 'actorUser')) { return $this->s->access->actorUser(); }
        throw new RuntimeException('TrainingActorUserUnavailable');
    }

    private function allow(): void
    {
        $this->s->access->requireDomain('session', 'write');
    }

    private function link(string $sourceType, int $sourceId, string $targetType, int $targetId, string $identity): void
    {
        $entity = $this->s->access->entity();
        $rows = $this->s->rows('SELECT rowid, fk_target FROM '.$this->s->table('native_link').
            ' WHERE entity='.$entity.' AND source_type='.$this->s->text($sourceType).' AND fk_source='.$sourceId.
            ' AND target_type='.$this->s->text($targetType).' FOR UPDATE');
        if ($rows) {
            if ((int)$rows[0]->fk_target !== $targetId) { throw new RuntimeException('TrainingNativeMappingConflict'); }
            return;
        }
        $this->s->query('INSERT INTO '.$this->s->table('native_link').
            ' (entity,source_type,fk_source,target_type,fk_target,identity_key,datec,fk_user_author,fk_user_mod)'.
            ' VALUES ('.$entity.','.$this->s->text($sourceType).','.$sourceId.','.$this->s->text($targetType).','.$targetId.','.
            $this->s->text($identity).','.$this->s->now().','.$this->s->access->actor().','.$this->s->access->actor().')');
    }

    private function mapping(string $sourceType, int $sourceId, string $targetType): ?int
    {
        $rows=$this->s->rows('SELECT fk_target FROM '.$this->s->table('native_link').
            ' WHERE entity='.$this->s->access->entity().' AND source_type='.$this->s->text($sourceType).
            ' AND fk_source='.$sourceId.' AND target_type='.$this->s->text($targetType));
        return $rows ? (int)$rows[0]->fk_target : null;
    }

    private function slotTimes($slot, string $timezone): array
    {
        $zone=new DateTimeZone($timezone);
        return array(
            new DateTimeImmutable($slot->start_utc, new DateTimeZone('UTC')),
            new DateTimeImmutable($slot->end_utc, new DateTimeZone('UTC'))
        );
    }

    public function syncSession(int $sessionId): int
    {
        $this->allow();
        if (!function_exists('isModEnabled') || !isModEnabled('project')) {
            throw new RuntimeException('TrainingProjectModuleRequired');
        }
        return $this->s->transaction(function() use ($sessionId) {
            $session=$this->s->session($sessionId,true);
            $slots=$this->s->rows('SELECT * FROM '.$this->s->table('session_slot').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' ORDER BY position');
            $start=$slots ? new DateTimeImmutable($slots[0]->start_utc,new DateTimeZone('UTC')) : null;
            $end=$slots ? new DateTimeImmutable($slots[count($slots)-1]->end_utc,new DateTimeZone('UTC')) : null;
            $projectId=$this->mapping('session',$sessionId,'project');
            if ($projectId) {
                $project=$this->native->project($projectId);
                $this->native->updateProject($project,$session->label,'Training session '.$session->ref,$start,$end);
            } else {
                $project=$this->native->createProject($session->ref,$session->label,'Training session '.$session->ref,$start,$end);
                $this->link('session',$sessionId,'project',(int)$project->id,'training:session:'.$this->s->access->entity().':'.$sessionId.':project');
                $projectId=(int)$project->id;
            }
            $this->s->audit('session',$sessionId,'dolibarr_project_synced',array('project_id'=>$projectId));
            return $projectId;
        });
    }

    public function syncSlot(int $sessionId, int $slotId): int
    {
        $this->allow();
        if (!function_exists('isModEnabled') || !isModEnabled('agenda')) {
            throw new RuntimeException('TrainingAgendaModuleRequired');
        }
        return $this->s->transaction(function() use ($sessionId,$slotId) {
            $session=$this->s->session($sessionId,true);
            $slots=$this->s->rows('SELECT * FROM '.$this->s->table('session_slot').' WHERE rowid='.$slotId.' AND entity='.$this->s->access->entity().' AND fk_session='.$sessionId);
            if (!$slots) { throw new RuntimeException('TrainingSessionSlotNotFound'); }
            $slot=$slots[0];
            $projectId=$this->mapping('session',$sessionId,'project') ?? $this->syncSession($sessionId);
            $times=$this->slotTimes($slot,$session->timezone);
            $actionId=$this->mapping('session_slot',$slotId,'actioncomm');
            $label=$session->label.' — '.$session->ref;
            if ($actionId) {
                $action=$this->native->action($actionId);
                $this->native->updateAction($action,$label,$times[0],$times[1],$projectId);
            } else {
                $action=$this->native->createAction($label,$times[0],$times[1],$projectId);
                $this->link('session_slot',$slotId,'actioncomm',(int)$action->id,'training:session_slot:'.$this->s->access->entity().':'.$slotId.':actioncomm');
                $actionId=(int)$action->id;
            }
            $this->s->audit('session_slot',$slotId,'dolibarr_actioncomm_synced',array('actioncomm_id'=>$actionId,'project_id'=>$projectId));
            return $actionId;
        });
    }

    public function syncSessionAgenda(int $sessionId): array
    {
        $this->allow();
        $projectId=$this->syncSession($sessionId);
        $slots=$this->s->rows('SELECT rowid FROM '.$this->s->table('session_slot').' WHERE entity='.$this->s->access->entity().' AND fk_session='.$sessionId.' ORDER BY position');
        $actions=array();
        foreach($slots as $slot){ $actions[]=$this->syncSlot($sessionId,(int)$slot->rowid); }
        return array('project_id'=>$projectId,'actioncomm_ids'=>$actions);
    }
}