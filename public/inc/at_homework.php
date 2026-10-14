<?php
declare(strict_types=1);
require_once __DIR__.'/at_services.php';
function at_homework_token(array $h): string {
    return hash('sha256',json_encode(array_map(fn($k)=>(string)($h[$k]??''),['id','track_id','title','instructions','due_date','status','client_reported_at']),JSON_THROW_ON_ERROR));
}
function at_homework_load(int $id,int $actor,bool $lock=false): array {
    if(!at_can('clients'))throw new DomainException('Client access is required.');
    $q=db()->prepare('SELECT h.*,t.client_id FROM homework h JOIN training_tracks t ON t.id=h.track_id WHERE h.id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$h=$q->fetch();
    if(!$h)throw new DomainException('Homework not found.');at_client((int)$h['client_id'],$actor);return $h;
}
function at_homework_update(int $id,array $input,int $actor): void {
    foreach (['edit_token','title','instructions','due_date'] as $key) if (isset($input[$key]) && !is_scalar($input[$key])) throw new DomainException('Homework fields must contain a single value.');
    db()->beginTransaction();
    try{
        $h=at_homework_load($id,$actor,true);
        if(!hash_equals(at_homework_token($h),(string)($input['edit_token']??'')))throw new DomainException('This assignment changed. Reload it before saving.');
        // Browsers post line breaks as CRLF while the app and notes store LF, so line
        // endings are normalised before storing and comparing; a due-date-only edit is
        // then not mistaken for a text change that clears the client's report.
        $lf=fn(string $s):string=>trim(str_replace(["\r\n","\r"],"\n",$s));
        $title=trim((string)($input['title']??''));$instructions=$lf((string)($input['instructions']??''));$due=trim((string)($input['due_date']??''));
        // Instructions may be as long as when they were assigned (the app and the track
        // page store up to the column's 65,535 bytes), so any assignment can be edited.
        if($title===''||mb_strlen($title)>190||strlen($instructions)>65535)throw new DomainException('Use a title up to 190 characters and instructions up to 65,535 bytes (about 65,000 plain characters).');
        // The same year range as assigning homework (track page and app): a mistyped year
        // (0026, 2062) would make the assignment long overdue or never due.
        if($due!==''&&!valid_date($due,2000,(int)date('Y')+5))throw new DomainException('Use a valid due date with a four-digit year from 2000 to '.((int)date('Y')+5).'.');
        $changed=$title!==trim((string)$h['title'])||$instructions!==$lf((string)$h['instructions']);
        db()->prepare('UPDATE homework SET title=?,instructions=?,due_date=?,client_reported_at=? WHERE id=?')->execute([$title,$instructions?:null,$due?:null,$changed?null:$h['client_reported_at'],$id]);
        audit($actor,'homework.edit','track',(int)$h['track_id'],'Assignment #'.$id);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
