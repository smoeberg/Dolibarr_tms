<?php
$pickerSession = $scheduling->create($first,'PICKER-01','Picker service test',1,'Europe/Copenhagen');
$scheduling->replaceSlots($pickerSession,$slots);$scheduling->changeStatus($pickerSession,'open');
$pickerEnrollment=$enrollments->confirm($pickerSession,26);
$pickerSlot=(int) $scheduling->detail($pickerSession)['slots'][0]->rowid;
$pickerAttendance=new TrainingAttendanceService($store);
$pickerInput=array('status'=>'present','arrival_local'=>'2026-10-20T09:14','departure_local'=>'2026-10-20T15:00');
$pickerAttendance->record($pickerSession,$pickerSlot,$pickerEnrollment,0,$pickerInput);
$row=$pickerAttendance->sheet($pickerSession,$pickerSlot)['rows'][0];
verify($row->status==='late' && $row->arrival_utc==='2026-10-20 07:14:00' && (int) $row->present_minutes===346,'picker input goes through service rules and records actual resulting status');
attendanceReject(fn() => $pickerAttendance->record($pickerSession,$pickerSlot,$pickerEnrollment,0,array('status'=>'absent'),'Stale UI'), 'TrainingAttendanceConflict');
$pickerAttendance->record($pickerSession,$pickerSlot,$pickerEnrollment,1,array('status'=>'present','arrival_local'=>'','departure_local'=>'','arrival_offset'=>'+02:00'),'Attest full slot from signed sheet');
$events=$pickerAttendance->history($pickerSession,$pickerSlot,$pickerEnrollment);
$change=json_decode($events[1]->metadata_json,true);
verify(count($events)===2 && $change['before']['status']==='late' && $change['after']['arrival_utc']===null && $change['after']['present_minutes']===420,'picker correction keeps human-readable before/after data with full attestation');
echo 'All attendance picker MySQL tests passed.'.PHP_EOL;
