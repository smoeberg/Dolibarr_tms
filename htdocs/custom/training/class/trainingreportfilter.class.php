<?php
/** One validated filter contract for overview totals, rows and navigation. */
final readonly class TrainingReportFilter
{
    public string $status;
    public string $time;
    public string $search;
    public string $timezone;
    public string $from;
    public string $to;
    public ?string $fromUtc;
    public ?string $untilUtc;
    public function __construct(array $input) {
        foreach (array('status','time','search','timezone','from','to') as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        }
        $this->status=$input['status'] ?? 'open'; $this->time=$input['time'] ?? '';
        $this->search=trim($input['search'] ?? ''); $this->timezone=$input['timezone'] ?? 'Europe/Copenhagen';
        $this->from=$input['from'] ?? ''; $this->to=$input['to'] ?? '';
        if (!in_array($this->status,array('','draft','open','closed'),true) || !in_array($this->time,array('','upcoming','ongoing','finished','unscheduled'),true) || strlen($this->search)>100 || !in_array($this->timezone,DateTimeZone::listIdentifiers(),true)) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        $start=$this->day($this->from); $end=$this->day($this->to);
        if ($start && $end && $start>$end) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        $utc=new DateTimeZone('UTC');
        $this->fromUtc=$start ? $start->setTimezone($utc)->format('Y-m-d H:i:s') : null;
        $this->untilUtc=$end ? $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s') : null;
    }
    private function day(string $value): ?DateTimeImmutable {
        if ($value==='') { return null; }
        if (!preg_match('/^[12][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value)) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone($this->timezone));
        if (!$day || $day->format('Y-m-d')!==$value) { throw new InvalidArgumentException('TrainingInvalidReportFilter'); }
        return $day;
    }
    public function query(int $page=1): string {
        return http_build_query(array('status'=>$this->status,'time'=>$this->time,'search'=>$this->search,'timezone'=>$this->timezone,'from'=>$this->from,'to'=>$this->to,'page'=>$page),'','&',PHP_QUERY_RFC3986);
    }
}
