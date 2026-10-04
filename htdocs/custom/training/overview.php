<?php
require_once __DIR__.'/lib/ui.lib.php';
require_once __DIR__.'/class/trainingreportingservice.class.php';
$store=new TrainingStore($db,trainingAccess());
try { $store->access->requireDomain('session','read'); $store->access->requireDomain('enrollment','read'); } catch (Throwable $e) { accessforbidden(); }
$error=''; $result=null; $filter=null;
try {
    $input=array(); foreach (array('status','time','search','timezone','from','to') as $key) { if (GETPOSTISSET($key)) { $input[$key]=GETPOST($key,'alphanohtml'); } }
    $filter=new TrainingReportFilter($input);
    $result=(new TrainingReportingService($store))->sessions($filter,GETPOSTISSET('page') ? GETPOSTINT('page') : 1);
} catch (Throwable $e) { $error=trainingError($e); }
llxHeader('', $langs->trans('TrainingOverview'));
print '<h2>'.trainingEscape($langs->trans('TrainingOverview')).'</h2><p>'.trainingEscape($langs->trans('TrainingOverviewHelp')).'</p>';
if ($error) { print '<div class="error">'.$error.'</div>'; }
print '<form method="get" action="'.dol_buildpath('/training/overview.php',1).'">';
foreach (array('status'=>array(''=>'All','draft'=>'TrainingSessionDraft','open'=>'TrainingSessionOpen','closed'=>'TrainingSessionClosed'),'time'=>array(''=>'All','upcoming'=>'TrainingTimeUpcoming','ongoing'=>'TrainingTimeOngoing','finished'=>'TrainingTimeFinished','unscheduled'=>'TrainingTimeUnscheduled')) as $field=>$choices) {
    print '<label>'.trainingEscape($langs->trans($field==='status' ? 'Status' : 'TrainingTimeStatus')).' <select name="'.$field.'">';
    $selected=$filter ? $filter->$field : GETPOST($field,'alphanohtml');
    foreach ($choices as $value=>$label) { print '<option value="'.$value.'"'.($selected===$value ? ' selected' : '').'>'.trainingEscape($langs->trans($label)).'</option>'; }
    print '</select></label> ';
}
foreach (array('from'=>'TrainingReportFrom','to'=>'TrainingReportTo','timezone'=>'TrainingTimezone','search'=>'Search') as $field=>$label) {
    $value=$filter ? $filter->$field : GETPOST($field,'alphanohtml');
    print '<label>'.trainingEscape($langs->trans($label)).' <input type="'.(in_array($field,array('from','to'),true) ? 'date' : 'text').'" name="'.$field.'" value="'.trainingEscape($value).'"'.($field==='search' ? ' maxlength="100"' : '').'></label> ';
}
print '<button class="button">'.trainingEscape($langs->trans('Search')).'</button></form>';
if ($result) {
    print '<p>'.trainingEscape($langs->trans('TrainingReportAsOf')).': '.trainingEscape(trainingLocal($result['as_of'],$filter->timezone)).'</p>';
    print '<table class="liste"><tr class="liste_titre"><th>'.trainingEscape($langs->trans('TrainingReportMetric')).'</th><th>'.trainingEscape($langs->trans('Value')).'</th></tr>';
    foreach (array('sessions'=>'TrainingSessions','confirmed'=>'TrainingConfirmedSeats','reserved'=>'TrainingReservedSeats','capacity'=>'TrainingCapacity','available'=>'TrainingUnusedCapacity') as $key=>$label) {
        print '<tr><td>'.trainingEscape($langs->trans($label)).'</td><td><a href="'.dol_buildpath('/training/overview.php',1).'?'.trainingEscape($filter->query()).'#holds">'.$result['totals'][$key].'</a></td></tr>';
    }
    print '<tr><td>'.trainingEscape($langs->trans('TrainingWeightedOccupancy')).'</td><td>'.($result['totals']['occupancy_percent']===null ? '—' : trainingEscape($result['totals']['occupancy_percent']).' %').'</td></tr></table>';
    print '<h3 id="holds">'.trainingEscape($langs->trans('TrainingSessions')).'</h3><table class="liste centpercent"><tr class="liste_titre">';
    foreach (array('Ref','TrainingCourse','Status','TrainingTimeStatus','TrainingReportFirstStart','TrainingConfirmedSeats','TrainingReservedSeats','TrainingCapacity','TrainingUnusedCapacity') as $key) { print '<th>'.trainingEscape($langs->trans($key)).'</th>'; }
    print '</tr>';
    foreach ($result['rows'] as $row) {
        print '<tr><td><a href="'.dol_buildpath('/training/session.php',1).'?id='.(int) $row->rowid.'">'.trainingEscape($row->ref).'</a></td><td><a href="'.dol_buildpath('/training/course.php',1).'?id='.(int) $row->product_id.'">'.trainingEscape($row->course_ref.' — '.$row->course_label).'</a></td><td>'.trainingEscape($langs->trans('TrainingSession'.ucfirst($row->status))).'</td><td>'.trainingEscape($langs->trans('TrainingTime'.ucfirst($row->time_status))).'</td><td>'.($row->first_utc ? trainingEscape(trainingLocal($row->first_utc,$filter->timezone)) : '—').'</td>';
        foreach (array('confirmed','reserved','capacity','available') as $key) { print '<td>'.$row->$key.'</td>'; }
        print '</tr>';
    }
    print '</table><p>'.trainingEscape($langs->trans('TrainingReportPage',$result['page'],$result['pages'])).' ';
    foreach (array(-1=>'Previous',1=>'Next') as $step=>$label) {
        $next=$result['page']+$step;
        if ($next>0 && $next<=$result['pages']) { print '<a class="button" href="'.dol_buildpath('/training/overview.php',1).'?'.trainingEscape($filter->query($next)).'">'.trainingEscape($langs->trans($label)).'</a> '; }
    }
    print '</p>';
}
llxFooter(); $db->close();
