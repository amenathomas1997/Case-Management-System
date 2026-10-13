<?php
declare(strict_types=1);
require_once __DIR__ . '/at_catalog.php';

function at_can(string $permission): bool { return can($permission) || can('billing'); }

function at_ready(): bool {
    try { db()->query('SELECT id FROM at_records LIMIT 0'); return true; }
    catch (PDOException $e) { if (in_array((string)$e->getCode(), ['42S02','42S22'], true)) return false; throw $e; }
}
function at_definition(string $kind): array {
    $def=at_catalog()[$kind] ?? null;
    if (!$def) throw new DomainException('Unknown AT service.');
    if (!at_can($def['perm'])) throw new DomainException('Your account cannot access this AT service.');
    return $def;
}
function at_client(int $id, int $actor): array {
    $q=db()->prepare('SELECT id,name,assigned_to FROM clients WHERE id=?'); $q->execute([$id]); $c=$q->fetch();
    if (!$c || (caseload_scoped() && (int)$c['assigned_to']!==$actor)) throw new DomainException('Client not found on your caseload.');
    return $c;
}
function at_scope(int $actor, string $alias='r'): string {
    return caseload_scoped() ? " AND ($alias.client_id IS NULL OR EXISTS (SELECT 1 FROM clients sc WHERE sc.id=$alias.client_id AND sc.assigned_to=" . (int)$actor . '))' : '';
}
function at_record(int $id, int $actor, bool $lock=false): array {
    $q=db()->prepare('SELECT r.* FROM at_records r WHERE r.id=?' . at_scope($actor) . ($lock?' FOR UPDATE':'')); $q->execute([$id]); $r=$q->fetch();
    if (!$r) throw new DomainException('Record not found on your caseload.');
    at_definition($r['kind']);
    $r['details']=json_decode($r['details'],true,512,JSON_THROW_ON_ERROR);
    return $r;
}
function at_editable(array $def, string $kind, array $input, int $actor): void {
    if ($kind==='resource' && !at_can('templates')) throw new DomainException('Template access is required to maintain the shared learning library.');
    if ($kind==='staff_profile' && !at_can('admin') && (int)($input['staff_id']??0)!==$actor) throw new DomainException('Only administrators can edit another instructor’s profile.');
}
function at_values(array $r): array {
    $values=$r['details'];
    foreach (['client_id','track_id','equipment_id','staff_id','parent_id','due_date'] as $key) $values[$key]=(string)($r[$key]??'');
    foreach ($values as &$v) $v=(string)($v??''); unset($v);
    return $values;
}
function at_public_record(array $r): array {
    return ['id'=>(int)$r['id'],'kind'=>$r['kind'],'title'=>$r['title'],'status'=>$r['status'],'version'=>(int)$r['version'], 'values'=>(object)at_values($r),'updated_at'=>$r['updated_at']];
}
function at_list(string $kind,int $actor,int $client=0): array {
    at_definition($kind);
    if ($client) at_client($client,$actor);
    $q=db()->prepare('SELECT r.* FROM at_records r WHERE r.kind=?' . at_scope($actor) . ($client?' AND r.client_id=?':'') . ' ORDER BY r.due_date IS NULL,r.due_date,r.updated_at DESC,r.id DESC');
    $q->execute($client?[$kind,$client]:[$kind]);
    return array_map(function($r){$r['details']=json_decode($r['details'],true);return at_public_record($r);},$q->fetchAll());
}
/** Choices are permission-filtered and client-bound. Server validation repeats every check.
 *  $kind is the record kind the choice is for: a licence's renewal contact is chosen by
 *  whoever holds the licence module's own permission (equipment). */
function at_choices(string $type,int $actor,int $client=0,string $kind=''): array {
    $scope=caseload_scoped()?' AND c.assigned_to='.(int)$actor:'';
    if ($client) { at_client($client,$actor); $scope.=' AND c.id='.(int)$client; }
    $sql=match($type) {
        'client'=>'SELECT c.id,c.name AS label FROM clients c WHERE 1=1'.$scope.' ORDER BY c.name',
        'track'=>'SELECT t.id,CONCAT(c.name,\' — \',t.title) AS label FROM training_tracks t JOIN clients c ON c.id=t.client_id WHERE 1=1'.$scope.' ORDER BY c.name,t.title',
        'equipment'=>at_can('equipment')?'SELECT id,name AS label FROM equipment ORDER BY name':null,
        'staff'=>at_can('schedule')||at_can('admin')||($kind==='license'&&at_can('equipment'))?'SELECT id,name AS label FROM users WHERE is_active=1 ORDER BY name':null,
        'document'=>'SELECT d.id,CONCAT(c.name,\' — \',d.title) AS label FROM client_documents d JOIN clients c ON c.id=d.client_id WHERE 1=1'.$scope,
        'invoice'=>at_can('billing')?'SELECT i.id,i.invoice_number AS label FROM invoices i JOIN training_tracks t ON t.id=i.track_id JOIN clients c ON c.id=t.client_id WHERE 1=1'.$scope:null,
        'request'=>at_can('procurement')?'SELECT d.id,CONCAT(c.name,\' — \',d.device_name) AS label FROM device_requests d JOIN clients c ON c.id=d.client_id WHERE 1=1'.$scope:null,
        'referral'=>at_can('referrals')?'SELECT d.id,CONCAT(\'Referral #\',d.id) AS label FROM referrals d JOIN clients c ON c.id=d.client_id WHERE 1=1'.$scope:null,
        'contract'=>at_can('billing')?'SELECT id,name AS label FROM contracts ORDER BY name':null,
        default=>null,
    };
    if ($sql) return array_map(fn($r)=>['value'=>(string)$r['id'],'label'=>$r['label']],db()->query($sql)->fetchAll());
    $kind=$type==='option'?'trial_option':$type;
    if (!isset(at_catalog()[$kind]) || !at_can(at_catalog()[$kind]['perm'])) return [];
    $q=db()->prepare('SELECT r.id,r.title,r.status FROM at_records r WHERE r.kind=?'.at_scope($actor).($client && !in_array($kind,['resource','license','staff_profile'],true)?' AND r.client_id='.(int)$client:'').' ORDER BY r.title');$q->execute([$kind]);
    $rows=$q->fetchAll();
    return array_values(array_map(fn($r)=>['value'=>(string)$r['id'],'label'=>$r['title'].' ('.$r['status'].')'],array_filter($rows,fn($r)=>$kind!=='resource'||$r['status']==='Published')));
}
function at_fields(string $kind,int $actor,int $client=0,?array $record=null): array {
    $def=at_definition($kind);$result=[];
    foreach($def['fields'] as $f){
        if(!in_array($f['type'],['text','textarea','url','date','money','score','integer','select'],true)){
            $f['options']=at_choices($f['type'],$actor,$client,$kind);
            // Existing assignments retain their parent even after library archival.
            if($kind==='learning' && $f['key']==='parent_id' && !empty($record['parent_id'])){
                $parent=at_record((int)$record['parent_id'],$actor);
                if($parent['kind']==='resource' && !in_array((string)$parent['id'],array_column($f['options'],'value'),true))
                    $f['options'][]=['value'=>(string)$parent['id'],'label'=>$parent['title'].' ('.$parent['status'].')'];
            }
            $f['type']='reference';
        } else $f['options']=array_map(fn($v)=>['value'=>$v,'label'=>$v],$f['options']);
        $result[]=$f;
    }
    return $result;
}
/** Dates on which something was done; any other date (a due, renewal, warranty or
 *  effective date) may lie further ahead. */
const AT_DONE_DATES=['tested_on','delivered_on','completed_on','installed_on','received_on','spent_on','submitted_on','decision_on'];
function at_validate_field(array $f,mixed $raw,int $actor,int $client,string $kind=''): string {
    if (!is_scalar($raw) && $raw!==null) throw new DomainException($f['label'].': invalid value.');
    $v=trim((string)$raw);
    if ($v==='') { if($f['required'])throw new DomainException($f['label'].' is required.'); return ''; }
    if(mb_strlen($v)>($f['type']==='textarea'?10000:500))throw new DomainException($f['label'].' is too long.');
    switch($f['type']){
        case 'date':
            if(!valid_date($v))throw new DomainException($f['label'].': use YYYY-MM-DD.');
            // A mistyped year (0026, 2062) is refused rather than stored, where it would
            // make a check overdue at once or never due.
            $maxYear=in_array($f['key'],AT_DONE_DATES,true)?(int)date('Y')+1:2099;
            if(!valid_date($v,2000,$maxYear))throw new DomainException($f['label'].': use a year from 2000 to '.$maxYear.' (YYYY-MM-DD).');
            break;
        case 'url': if(!filter_var($v,FILTER_VALIDATE_URL)||strtolower((string)parse_url($v,PHP_URL_SCHEME))!=='https'||parse_url($v,PHP_URL_USER)||parse_url($v,PHP_URL_PASS))throw new DomainException('Use an HTTPS link without embedded credentials.');
            // Stored with a lower-case scheme, so pages that only link https: URLs accept it.
            $v=preg_replace('/^https:/i','https:',$v);break;
        case 'money': if(!preg_match('/^\d{1,8}(\.\d{1,2})?$/D',$v))throw new DomainException($f['label'].': enter a nonnegative amount with at most two decimal places.');break;
        case 'score': if(!in_array($v,['1','2','3','4','5'],true))throw new DomainException($f['label'].': choose 1 through 5.');break;
        case 'integer': if(!ctype_digit($v)||(int)$v<1||(int)$v>100000)throw new DomainException($f['label'].': enter a whole number from 1 to 100000.');break;
        case 'select': if(!in_array($v,$f['options'],true))throw new DomainException($f['label'].': choose a listed value.');break;
        case 'text': case 'textarea': break;
        default: if(!in_array($v,array_column(at_choices($f['type'],$actor,$client,$kind),'value'),true)){
            // A library resource the editor can see but that is not Published gets a message saying so.
            if($f['type']==='resource'&&ctype_digit($v)&&at_can(at_catalog()['resource']['perm'])){
                $q=db()->prepare("SELECT r.status FROM at_records r WHERE r.id=? AND r.kind='resource'".at_scope($actor));$q->execute([(int)$v]);$rs=$q->fetchColumn();
                if($rs!==false&&$rs!=='Published')throw new DomainException($f['label'].': this resource is '.$rs.'. Publish this resource before assigning it to a client.');
            }
            throw new DomainException($f['label'].': choose an accessible record belonging to this client.');
        }
    }
    return $v;
}
function at_save(string $kind,array $input,int $actor): int {
    foreach ($input as $value) if (!is_scalar($value) && $value!==null) throw new DomainException('Record fields must contain a single value.');
    $def=at_definition($kind);at_editable($def,$kind,$input,$actor);
    $pdo=db();$pdo->beginTransaction();
    try{
        $id=(int)($input['id']??0);
        // Lock the inventory row before the workflow record, the order loan_equipment()
        // uses (equipment, then at_records), so saving a repair or reuse record cannot
        // deadlock with a loan of the same device. The device cannot change after creation.
        if($id && in_array($kind,['repair','reuse'],true)){
            $current=at_record($id,$actor);
            if($current['equipment_id']){$q=$pdo->prepare('SELECT id FROM equipment WHERE id=? FOR UPDATE');$q->execute([(int)$current['equipment_id']]);}
        }
        // Lock a shared parent before its child, matching subscription/setup edits.
        if(isset($def['parent'])){
            $prior=$id?at_record($id,$actor):null;
            $parentLock=(int)($prior['parent_id']??$input['parent_id']??0);
            if($parentLock)at_record($parentLock,$actor,true);
        }
        $old=$id?at_record($id,$actor,true):null;
        if($old && ($old['kind']!==$kind||(int)($input['version']??0)!==(int)$old['version']))throw new DomainException('This record changed. Reload it before saving.');
        if($old)at_editable($def,$kind,at_values($old),$actor);
        $client=(int)($input['client_id']??0);
        if($def['client'] || $client)at_client($client,$actor);
        // Prevent moving a parent away from its child records or changing a shared record into a client record.
        if($old && (int)$old['client_id']!==$client)throw new DomainException('A record’s client cannot be changed. Create a new record for the other client.');
        if(!$def['client'] && $client)throw new DomainException('This is a shared record; use its client-assignment workflow.');
        $title=trim((string)($input['title']??''));if($title===''||mb_strlen($title)>190)throw new DomainException('Enter a title of up to 190 characters.');
        $status=(string)($input['status']??$def['statuses'][0]);if(!in_array($status,$def['statuses'],true))throw new DomainException('Invalid status.');
        $values=[];$stored=$old?at_values($old):[];
        foreach($def['fields'] as $f){
            $raw=$input[$f['key']]??'';
            if($old && !in_array($f['type'],['text','textarea','url','date','money','score','integer','select'],true) && is_scalar($raw) && trim((string)$raw)!=='' && trim((string)$raw)===($stored[$f['key']]??'')){
                // A reference the record already holds is kept without re-checking it
                // against this editor's choices: they may not be allowed to list it
                // (another module's record, a departed instructor), and the client
                // cannot change, so it still belongs to this client. A parent's scope
                // and type are checked below under the parent lock. Only a changed
                // reference is validated against the editor's choices.
                $values[$f['key']]=$stored[$f['key']];
            }else $values[$f['key']]=at_validate_field($f,$raw,$actor,$client,$kind);
        }
        $parent=(int)($values['parent_id']??0);
        if($old && (int)$old['parent_id']!==$parent)throw new DomainException('The parent record cannot be changed after creation.');
        if($parent){$pr=at_record($parent,$actor,true);if($pr['kind']!==($def['parent']??''))throw new DomainException('Incorrect parent record.');if($pr['client_id'] && (int)$pr['client_id']!==$client)throw new DomainException('The parent belongs to another client.');}
        // A comparison's options follow it (checked under the parent lock): none is added
        // once it is Completed or Cancelled, and its recommended option keeps the Trialled
        // status it was recommended with while the comparison stays Completed.
        if($kind==='trial_option' && $parent){
            if(!$old && in_array($pr['status'],['Completed','Cancelled'],true))throw new DomainException('Comparison: it is '.$pr['status'].'; set it back to In progress before adding an option.');
            if($old && $pr['status']==='Completed' && (string)($pr['details']['preferred_option']??'')===(string)$id && $status!=='Trialled')throw new DomainException('This option is the recommended option of a completed comparison, so its status stays Trialled. Set the comparison back to In progress before changing it.');
        }
        if($kind==='trial' && $status==='Completed'){
            // Each message starts with the field's label, so the form ties it to that control.
            if($values['preferred_option']==='')throw new DomainException('Recommended trial option is required to complete the comparison.');
            if($values['rationale']==='')throw new DomainException('Recommendation rationale is required to complete the comparison.');
            $option=at_record((int)$values['preferred_option'],$actor);
            if($option['kind']!=='trial_option'||(int)$option['parent_id']!==$id||$option['status']!=='Trialled')throw new DomainException('Recommended trial option: choose an option from this comparison whose status is Trialled.');
        }
        // Each status rule names one field and starts with its label, so the form ties
        // the message to that control.
        $label=array_column($def['fields'],'label','key');
        if($kind==='funding'){
            if(in_array($status,['Authorized','Delivering','Ready to bill','Submitted','Paid'],true)){
                foreach(['authorization','authorization_document'] as $key)if(!$values[$key])throw new DomainException($label[$key].' is required for the '.$status.' status.');
            }
            if(in_array($status,['Ready to bill','Submitted','Paid'],true)){
                if($values['service_evidence']!=='Yes')throw new DomainException($label['service_evidence'].': choose Yes before using the '.$status.' status.');
                if(!in_array($values['report_ready'],['Yes','Not applicable'],true))throw new DomainException($label['report_ready'].': choose Yes or Not applicable before using the '.$status.' status.');
            }
            if(in_array($status,['Denied','Appeal'],true)&&!$values['denial_reason'])throw new DomainException($label['denial_reason'].' is required for the '.$status.' status.');
            if(in_array($status,['Submitted','Paid'],true)){
                $q=$pdo->prepare('SELECT status,track_id FROM invoices WHERE id=?');$q->execute([(int)$values['invoice_id']]);$inv=$q->fetch();
                if(!$inv||($status==='Paid'?$inv['status']!=='Paid':!in_array($inv['status'],['Submitted','Paid'],true)))throw new DomainException($label['invoice_id'].': link the actual invoice, marked '.($status==='Paid'?'Paid':'Submitted or Paid').' in billing, before using this status.');
                if($values['track_id'] && (int)$inv['track_id']!==(int)$values['track_id'])throw new DomainException($label['invoice_id'].': choose an invoice that belongs to the selected training track.');
            }
        }
        if($kind==='outcome'&&$status==='Completed'){
            foreach(['completed_on','in_use','independence','confidence'] as $key)if(!$values[$key])throw new DomainException($label[$key].' is required to complete this check.');
        }
        if($kind==='outcome'&&$status==='Skipped'&&!$values['barriers'])throw new DomainException($label['barriers'].' is required when the check is skipped.');
        if($kind==='repair'){
            if($old && (int)$old['equipment_id']!==(int)($values['equipment_id']??0))throw new DomainException('The affected inventory device cannot change after the repair is created.');
            if($old && isset($old['details']['loan_id']))$values['loan_id']=$old['details']['loan_id'];
            if($old && isset($old['details']['prior_status']))$values['prior_status']=$old['details']['prior_status'];
            if(in_array($status,['Resolved','Cancelled'],true)&&!empty($values['loan_id'])){
                $q=$pdo->prepare('SELECT returned_at FROM equipment_loans WHERE id=?');$q->execute([(int)$values['loan_id']]);if(!$q->fetchColumn())throw new DomainException('Return the linked loaner before closing this repair.');
            }
            if($status==='Resolved'&&!$values['resolution'])throw new DomainException($label['resolution'].' is required to resolve the repair.');
        }
        if($kind==='license_seat'&&$status==='Assigned'){
            if(!in_array($pr['status'],['Active','Renewal due'],true)||($pr['due_date'] && $pr['due_date']<date('Y-m-d')))throw new DomainException('This subscription is not current. Renew it before assigning a seat.');
            $q=$pdo->prepare("SELECT id FROM at_records WHERE kind='license_seat' AND parent_id=? AND status='Assigned' AND id<>? FOR UPDATE");$q->execute([$parent,$id]);
            if(count($q->fetchAll())>=(int)$pr['details']['seats'])throw new DomainException('All purchased seats are assigned. Release a seat or increase the purchased count.');
        }
        if($kind==='license'&&$id){$q=$pdo->prepare("SELECT id FROM at_records WHERE parent_id=? AND kind='license_seat' AND status='Assigned' FOR UPDATE");$q->execute([$id]);$assigned=count($q->fetchAll());if($assigned>(int)$values['seats'])throw new DomainException($label['seats'].': release assigned seats before reducing the purchased count.');
            // An expired or cancelled subscription leaves no client holding a seat of it.
            if($assigned && in_array($status,['Expired','Cancelled'],true) && !in_array($old['status'],['Expired','Cancelled'],true))throw new DomainException('Release the '.$assigned.' assigned seat'.($assigned===1?'':'s').' (License assignments) before marking this subscription '.$status.'.');}
        $source=null;
        if(in_array($kind,['staff_profile','rate'],true)){
            $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([(int)$values['staff_id']]);
            $source=$kind.':'.$values['staff_id'].($kind==='rate'?':'.$values['due_date']:'');
            $q=$pdo->prepare('SELECT id FROM at_records WHERE source_key=? AND id<>?');$q->execute([$source,$id]);if($q->fetchColumn())throw new DomainException('A record already exists for this instructor and effective date. Edit that record instead.');
        }
        $retired=['count'=>0,'holders'=>[]];
        if($kind==='reuse')$retired=at_validate_reuse($old,$values,$status,$id);
        // The device's inventory status follows the repair only when the repair is
        // created, closed or reopened. Editing an open repair, or the notes of a
        // closed one, leaves alone a status staff have since changed by hand.
        if($kind==='repair'&&!empty($values['equipment_id'])){
            $closed=['Resolved','Cancelled'];
            if(!$old||in_array($old['status'],$closed,true)!==in_array($status,$closed,true)){
                $before=at_repair_inventory((int)$values['equipment_id'],$client,$status,$id,$values['prior_status']??null);
                // Remember the device's status before the repair, so cancelling restores it.
                if(!in_array($status,$closed,true))$values['prior_status']=$before;
            }
        }
        $columns=[];foreach(['client_id','track_id','equipment_id','staff_id','parent_id','due_date'] as $key){$columns[$key]=($values[$key]??'')!==''?$values[$key]:null;unset($values[$key]);}
        $payload=json_encode($values,JSON_THROW_ON_ERROR);
        if($id){$q=$pdo->prepare('UPDATE at_records SET title=?,status=?,client_id=?,track_id=?,equipment_id=?,staff_id=?,parent_id=?,due_date=?,details=?,source_key=COALESCE(?,source_key),version=version+1 WHERE id=?');$q->execute([$title,$status,...array_values($columns),$payload,$source,$id]);}
        else{$q=$pdo->prepare('INSERT INTO at_records(kind,title,status,client_id,track_id,equipment_id,staff_id,parent_id,due_date,details,source_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$kind,$title,$status,...array_values($columns),$payload,$source,$actor]);$id=(int)$pdo->lastInsertId();}
        // Only when an active setup gets or changes its delivery date: a new record, the
        // move into Active, or a delivery date added to or corrected on a setup that was
        // already Active (only checks still Scheduled move). Re-saving with the same date
        // doesn't re-create or re-date manually adjusted outcome checks.
        if($kind==='setup'&&$status==='Active'&&!empty($values['delivered_on'])&&(!$old||$old['status']!=='Active'||(string)($old['details']['delivered_on']??'')!==$values['delivered_on']))at_schedule_outcomes($id,$columns,$values['delivered_on'],$title,$actor);
        // A setup that is retired or superseded is no longer followed up: its checks still
        // Scheduled are closed as Skipped, with the reason, so no reminder keeps coming.
        $skipped=0;
        if($kind==='setup'&&$old&&$old['status']==='Active'&&in_array($status,['Retired','Superseded'],true)){
            $q=$pdo->prepare("UPDATE at_records SET status='Skipped',details=IF(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(details,'$.barriers')),'')='',JSON_SET(details,'$.barriers',?),details),version=version+1 WHERE parent_id=? AND kind='outcome' AND status='Scheduled'");
            $q->execute(['Not checked: the device setup was marked '.$status.' on '.date('Y-m-d').'.',$id]);$skipped=$q->rowCount();
        }
        audit($actor,'at.save','at_record',$id,$kind.' '.$status.($retired['count']?'; '.$retired['count'].' equipment reservation'.($retired['count']===1?'':'s').' cancelled':'').($skipped?'; '.$skipped.' scheduled outcome check'.($skipped===1?'':'s').' skipped':''));
        $pdo->commit();
        if($retired['holders'])notify_retired_holders((int)$columns['equipment_id'],$retired['holders'],$actor,'recycled');
        return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function at_schedule_outcomes(int $id,array $columns,string $date,string $title,int $actor): void {
    foreach([30,60,90] as $days){
        $due=(new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d');
        db()->prepare("INSERT INTO at_records(kind,client_id,track_id,equipment_id,parent_id,title,status,due_date,details,source_key,created_by) VALUES ('outcome',?,?,?,?,?,'Scheduled',?,'{}',?,?) ON DUPLICATE KEY UPDATE due_date=IF(status='Scheduled',VALUES(due_date),due_date),version=version+1")
            ->execute([$columns['client_id'],$columns['track_id'],$columns['equipment_id'],$id,mb_substr($days.'-day check: '.$title,0,190),$due,'setup:'.$id.':'.$days,$actor]);
    }
}
/** Returns the holds cancelled when the device is recycled (see cancel_retired_holds()). */
function at_validate_reuse(?array $old,array $v,string $status,int $id): array {
    if(($old && $old['status']==='Redeployed')||$status==='Redeployed')throw new DomainException('Use the redeploy action to issue equipment. Redeployed intake records are retained as history.');
    if($old && (int)$old['equipment_id']!==(int)$v['equipment_id'])throw new DomainException('The inventory device cannot change after intake.');
    // A recycled record kept as Recycled is history: editing its notes or donor reference
    // neither re-checks nor touches the device, which may since have gone through a new intake.
    if($old && $old['status']==='Recycled' && $status==='Recycled')return ['count'=>0,'holders'=>[]];
    $q=db()->prepare('SELECT id FROM equipment WHERE id=? FOR UPDATE');$q->execute([(int)$v['equipment_id']]);
    $q=db()->prepare('SELECT id FROM equipment_loans WHERE equipment_id=? AND returned_at IS NULL FOR UPDATE');$q->execute([(int)$v['equipment_id']]);if($q->fetchColumn())throw new DomainException('Return the open equipment loan before starting reuse.');
    $q=db()->prepare("SELECT id FROM at_records WHERE kind='reuse' AND equipment_id=? AND id<>? AND status NOT IN ('Redeployed','Recycled') FOR UPDATE");$q->execute([(int)$v['equipment_id'],$id]);if($q->fetchColumn())throw new DomainException('This item already has an active reuse intake.');
    $q=db()->prepare("SELECT id FROM at_records WHERE kind='repair' AND equipment_id=? AND status NOT IN ('Resolved','Cancelled') FOR UPDATE");$q->execute([(int)$v['equipment_id']]);if($q->fetchColumn())throw new DomainException('Resolve the open repair before processing reuse.');
    if($status==='Ready'){
        // Each message starts with its field's label, so the form ties it to that control.
        $label=array_column(at_catalog()['reuse']['fields'],'label','key');
        foreach(['inspection','sanitized','data_wiped','repairs_done'] as $key)if(!in_array($v[$key],$key==='inspection'?['Yes']:['Yes','Not applicable'],true))throw new DomainException($label[$key].': choose '.($key==='inspection'?'Yes':'Yes or Not applicable').' before making this device available (Ready).');
        if(!$v['work_done'])throw new DomainException($label['work_done'].' is required before making this device available (Ready).');
    }
    // The device's inventory status follows the record only when the record is created
    // or its status changes, as with repairs: re-saving leaves the device alone.
    if($old && $old['status']===$status)return ['count'=>0,'holders'=>[]];
    db()->prepare('UPDATE equipment SET status=? WHERE id=?')->execute([$status==='Ready'?'Available':($status==='Recycled'?'Retired':'Repair'),(int)$v['equipment_id']]);
    // A recycled device is retired, so its holds and waitlist could never be met: cancelled
    // under the equipment lock taken above, as retiring it from the Equipment page does.
    return $status==='Recycled'?cancel_retired_holds(db(),(int)$v['equipment_id']):['count'=>0,'holders'=>[]];
}

/** Allocation holds the workflow row and inventory lock through the actual loan. */
function at_issue(int $id,int $equipment,int $client,?string $due,int $actor): int {
    if(!at_can('equipment'))throw new DomainException('Equipment access is required.');
    at_client($client,$actor);
    if($due!==null&&!valid_date($due))throw new DomainException('Use a valid loan return date.');
    $pdo=db();$pdo->beginTransaction();
    try{
        // Inventory row before the workflow record, the order loan_equipment() and
        // repair/reuse saves use, so these paths cannot deadlock.
        $q=$pdo->prepare('SELECT id FROM equipment WHERE id=? FOR UPDATE');$q->execute([$equipment]);
        $r=at_record($id,$actor,true);
        if($r['kind']==='repair'){
            if((int)$r['client_id']!==$client||in_array($r['status'],['Resolved','Cancelled'],true))throw new DomainException('Choose the repair client and an open repair.');
            if(!empty($r['details']['loan_id'])){
                $q=$pdo->prepare('SELECT returned_at FROM equipment_loans WHERE id=?');$q->execute([(int)$r['details']['loan_id']]);if(!$q->fetchColumn())throw new DomainException('This repair already has a loaner.');
            }
            if($equipment===(int)$r['equipment_id'])throw new DomainException('Choose a replacement different from the device under repair.');
        }elseif($r['kind']==='reuse'){
            if($r['status']!=='Ready'||$equipment!==(int)$r['equipment_id'])throw new DomainException('Complete reuse readiness before redeployment.');
            // Redeployment is permanent, so it goes only to a client whose case is open.
            $q=$pdo->prepare('SELECT status,archived_at FROM clients WHERE id=?');$q->execute([$client]);$c=$q->fetch();
            if(!$c||$c['archived_at']!==null||str_starts_with((string)$c['status'],'Closed'))throw new DomainException('Choose a client whose case is open: a redeployed device cannot go to a closed or archived client.');
        }else throw new DomainException('This record cannot issue equipment.');
        // A repair loaner is temporary, so it gets no "still in use?" follow-ups; a
        // redeployed reuse device is a real provision and does.
        $loan=loan_equipment($equipment,$client,$due,'AT service #'.$id,$actor,$r['kind']!=='repair');
        $r['details']['loan_id']=(string)$loan;
        $pdo->prepare('UPDATE at_records SET details=?,client_id=?,status=?,version=version+1 WHERE id=?')->execute([json_encode($r['details'],JSON_THROW_ON_ERROR),$client,$r['kind']==='reuse'?'Redeployed':$r['status'],$id]);
        audit($actor,'at.issue','at_record',$id,'Loan #'.$loan);
        $pdo->commit();return $loan;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** The clients a loaner or redeployed device can be issued to: a repair's own client,
 *  or, for a reuse record, each client in scope whose case is open (as at_issue() requires). */
function at_issue_clients(array $r,int $actor): array {
    $choices=at_choices('client',$actor,(int)$r['client_id']);
    if($r['kind']!=='reuse')return $choices;
    $open=array_flip(array_map('strval',db()->query("SELECT id FROM clients WHERE archived_at IS NULL AND status NOT LIKE 'Closed%'")->fetchAll(PDO::FETCH_COLUMN)));
    return array_values(array_filter($choices,fn($o)=>isset($open[$o['value']])));
}
function at_return(int $id,int $actor): void {
    if(!at_can('equipment'))throw new DomainException('Equipment access is required.');
    $r=at_record($id,$actor);if(!in_array($r['kind'],['repair','reuse'],true)||empty($r['details']['loan_id']))throw new DomainException('No linked loan.');
    if($r['client_id'])at_client((int)$r['client_id'],$actor);
    return_equipment((int)$r['details']['loan_id']);audit($actor,'at.return','at_record',$id);
}
function at_tokens(string $value): array {
    return array_values(array_unique(array_filter(array_map(fn($v)=>mb_strtolower(trim($v)),explode(',',$value)))));
}
/** Schedule load is aggregate only: do not disclose another instructor’s clients. */
function at_matches(array $need,int $actor): array {
    at_definition('staff_profile');$result=[];
    $start=(string)($need['date']??date('Y-m-d'));if(!valid_date($start))throw new DomainException('Choose a valid matching date.');
    $rows=at_list('staff_profile',$actor);
    foreach($rows as $r){
        if($r['status']!=='Available')continue;$v=(array)$r['values'];$matched=[];$missing=[];$score=0;
        foreach(['expertise','languages','areas'] as $key){foreach(at_tokens((string)($need[$key]??'')) as $term){if(in_array($term,at_tokens($v[$key]??''),true)){$matched[]=$term;$score++;}else $missing[]=$term;}}
        if(!empty($need['modality'])&&($v['modality']??'')!=='Both'&&($v['modality']??'')!==$need['modality'])$missing[]=$need['modality'];
        $q=db()->prepare('SELECT name,weekly_hours FROM users WHERE id=? AND is_active=1');$q->execute([(int)$v['staff_id']]);$staff=$q->fetch();if(!$staff)continue;
        // Booked time is counted as capacity.php counts it: a group session (one row per
        // participant, same start and group_uid) is one sitting, and sessions already
        // held (Completed / No Show) used the instructor's time; only cancelled ones are left out.
        $q=db()->prepare("SELECT COALESCE(SUM(mins),0)/60 FROM (SELECT MAX(TIMESTAMPDIFF(MINUTE,start_at,end_at)) AS mins FROM scheduled_sessions WHERE user_id=? AND status IN ('Scheduled','Completed','No Show') AND start_at>=? AND start_at<DATE_ADD(?,INTERVAL 7 DAY) GROUP BY start_at,COALESCE(group_uid,CONCAT('s',id))) x");$q->execute([(int)$v['staff_id'],$start,$start]);$hours=(float)$q->fetchColumn();
        $result[]=['id'=>(int)$v['staff_id'],'name'=>$staff['name'],'matched'=>implode(', ',$matched),'missing'=>implode(', ',$missing),'score'=>$score,'booked_hours'=>$hours,'capacity_hours'=>(float)$staff['weekly_hours'],'available_hours'=>max(0,(float)$staff['weekly_hours']-$hours),'availability'=>$v['availability'],'profile_id'=>$r['id']];
    }
    usort($result,fn($a,$b)=>(($a['missing']==='')?0:1)<=>(($b['missing']==='')?0:1) ?: $b['score']<=>$a['score'] ?: $b['available_hours']<=>$a['available_hours']);return $result;
}
/** Revenue is the total of invoices submitted to the funder or paid (shown as "Submitted to funder", matching
 *  track_rollup()['submitted_amount']); the 'invoiced' key is kept for API clients. Actual cash and unknown labor are reported separately. */
function at_profitability(int $actor): array {
    at_definition('cost');
    $q=db()->query('SELECT t.id,t.title,t.service_type,t.funding_agency,c.name AS client_name FROM training_tracks t JOIN clients c ON c.id=t.client_id WHERE 1=1'.(caseload_scoped()?' AND c.assigned_to='.(int)$actor:'').' ORDER BY c.name,t.title');
    $result=[];
    foreach($q->fetchAll() as $t){
        $id=(int)$t['id'];$s=db()->prepare("SELECT COALESCE(SUM(total_amount),0) billed,COALESCE(SUM(CASE WHEN status='Paid' THEN COALESCE(paid_amount,total_amount) ELSE COALESCE(paid_amount,0) END),0) received FROM invoices WHERE track_id=? AND status IN ('Submitted','Paid')");$s->execute([$id]);$money=$s->fetch();
        $s=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM track_expenses WHERE track_id=?');$s->execute([$id]);$expenses=(float)$s->fetchColumn();
        $s=db()->prepare("SELECT details FROM at_records WHERE kind='cost' AND track_id=? AND status='Recorded'");$s->execute([$id]);foreach($s->fetchAll() as $row)$expenses+=(float)(json_decode($row['details'],true)['amount']??0);
        $s=db()->prepare('SELECT hours,created_by,work_date FROM work_logs WHERE track_id=?');$s->execute([$id]);$labor=0;$unknown=0;
        foreach($s->fetchAll() as $w){
            $r=db()->prepare("SELECT details FROM at_records WHERE kind='rate' AND staff_id=? AND due_date<=? AND status='Active' ORDER BY due_date DESC,id DESC LIMIT 1");$r->execute([$w['created_by'],$w['work_date']]);$raw=$r->fetchColumn();
            if($raw===false)$unknown+=(float)$w['hours'];else $labor+=(float)$w['hours']*(float)(json_decode($raw,true)['hourly_cost']??0);
        }
        $contractQuery=db()->prepare("SELECT DISTINCT c.name FROM at_records r JOIN contracts c ON c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.contract_id')) AS UNSIGNED) WHERE r.kind='funding' AND r.track_id=? AND r.status<>'Cancelled' ORDER BY c.name");
        $contractQuery->execute([$id]);$contracts=$contractQuery->fetchAll(PDO::FETCH_COLUMN);
        $contract=count($contracts)===1?$contracts[0]:(count($contracts)>1?'Multiple contracts (unallocated)':'No contract linked');
        $revenue=(float)$money['billed'];$cost=round($expenses+$labor,2);
        $result[]=['id'=>$id,'title'=>$t['title'],'client'=>$t['client_name'],'service'=>$t['service_type'],'funder'=>$t['funding_agency'],'contract'=>$contract,'invoiced'=>$revenue,'received'=>(float)$money['received'],'cost'=>$cost,'margin'=>round($revenue-$cost,2),'unpriced_hours'=>$unknown];
    }
    return $result;
}
function at_financials(array $r): array {
    if($r['kind']!=='funding'||!at_can('billing')||!$r['track_id'])return [];
    $q=db()->prepare('SELECT auth_number,authorized_hours,hourly_rate,expiration_date FROM training_tracks WHERE id=?');$q->execute([$r['track_id']]);$t=$q->fetch();if(!$t)return [];
    $q=db()->prepare('SELECT COALESCE(SUM(hours),0) FROM work_logs WHERE track_id=?');$q->execute([$r['track_id']]);$used=(float)$q->fetchColumn();
    $q=db()->prepare("SELECT COALESCE(SUM(total_amount),0) total,COALESCE(SUM(CASE WHEN status='Paid' THEN COALESCE(paid_amount,total_amount) ELSE COALESCE(paid_amount,0) END),0) paid FROM invoices WHERE track_id=? AND status IN ('Submitted','Paid')");$q->execute([$r['track_id']]);$i=$q->fetch();
    return ['Authorization'=>$t['auth_number'],'Expires'=>$t['expiration_date']??'Not set','Authorized hours'=>(string)$t['authorized_hours'],'Delivered hours'=>(string)$used,'Submitted to funder'=>number_format((float)$i['total'],2),'Received'=>number_format((float)$i['paid'],2),'Outstanding'=>number_format((float)$i['total']-(float)$i['paid'],2)];
}
function at_detail(int $id,int $actor): array {
    $r=at_record($id,$actor);$def=at_definition($r['kind']);
    $q=db()->prepare('SELECT r.* FROM at_records r WHERE r.parent_id=?'.at_scope($actor).' ORDER BY r.id');$q->execute([$id]);$children=[];
    foreach($q->fetchAll() as $child){if(at_can(at_catalog()[$child['kind']]['perm'])){$child['details']=json_decode($child['details'],true);$children[]=at_public_record($child);}}
    $summary=at_financials($r);
    if(!empty($r['details']['loan_id'])&&at_can('equipment')){
        $q=db()->prepare('SELECT l.returned_at,l.due_date,e.name FROM equipment_loans l JOIN equipment e ON e.id=l.equipment_id WHERE l.id=? AND l.client_id=?');$q->execute([(int)$r['details']['loan_id'],$r['client_id']]);
        if($l=$q->fetch())$summary+=['Loaner'=>$l['name'],'Return due'=>$l['due_date']??'Not set','Returned'=>$l['returned_at']??'Not returned'];
    }
    if($r['kind']==='license'){
        // Aggregate allocation counts include all clients; child details stay scoped.
        $q=db()->prepare("SELECT COUNT(*) FROM at_records WHERE parent_id=? AND kind='license_seat' AND status='Assigned'");$q->execute([$id]);$count=(int)$q->fetchColumn();
        $summary+=['Purchased seats'=>(string)$r['details']['seats'],'Assigned seats'=>(string)$count,'Available seats'=>(string)max(0,(int)$r['details']['seats']-$count)];
    }
    return ['record'=>at_public_record($r),'fields'=>at_fields($r['kind'],$actor,(int)$r['client_id'],$r),'children'=>$children,'summary'=>(object)$summary,'statuses'=>$def['statuses'],'description'=>$def['description']];
}
function at_portal_learning(int $client): array {
    if(!at_ready())return [];
    $q=db()->prepare("SELECT a.id,a.title,a.status,a.due_date,a.details,r.details AS resource_details,r.status AS resource_status FROM at_records a JOIN at_records r ON r.id=a.parent_id AND r.kind='resource' WHERE a.kind='learning' AND a.client_id=? AND a.status<>'Cancelled' AND r.status='Published' ORDER BY a.due_date IS NULL,a.due_date,a.id");$q->execute([$client]);return $q->fetchAll();
}
function at_portal_report(int $id,int $client): int {
    $q=db()->prepare("UPDATE at_records a JOIN at_records r ON r.id=a.parent_id AND r.kind='resource' AND r.status='Published' SET a.status='Client reported',a.version=a.version+1 WHERE a.id=? AND a.kind='learning' AND a.client_id=? AND a.status IN ('Assigned','Practicing')");$q->execute([$id,$client]);
    return $q->rowCount();
}

/** The inventory row serializes repair/intake/loan mutations. Returns the device's
 *  inventory status as it was before this change. */
function at_repair_inventory(int $equipment,int $client,string $status,int $id,?string $prior=null): string {
    $q=db()->prepare('SELECT status FROM equipment WHERE id=? FOR UPDATE');$q->execute([$equipment]);$current=(string)$q->fetchColumn();
    if($current==='Retired')throw new DomainException('Retired equipment must go through reuse inspection first.');
    $q=db()->prepare('SELECT client_id FROM equipment_loans WHERE equipment_id=? AND returned_at IS NULL FOR UPDATE');$q->execute([$equipment]);$borrower=$q->fetchColumn();
    if($borrower && (int)$borrower!==$client)throw new DomainException('This equipment is currently loaned to another client.');
    $q=db()->prepare("SELECT id FROM at_records WHERE equipment_id=? AND id<>? AND ((kind='repair' AND status NOT IN ('Resolved','Cancelled')) OR (kind='reuse' AND status NOT IN ('Redeployed','Recycled'))) FOR UPDATE");$q->execute([$equipment,$id]);
    if($q->fetchColumn())throw new DomainException('This equipment already has an active repair or reuse workflow.');
    // Resolved certifies the device. Cancelled puts it back as it was when the repair
    // opened: on loan while the client still has it, otherwise its earlier status. A
    // repair recorded without that status stays in Repair, since cancelling does not
    // certify a broken item as safe to loan.
    if($id===0)$prior=$current;
    $state=match($status){
        'Resolved'=>$borrower?'On Loan':'Available',
        'Cancelled'=>$borrower?'On Loan':(in_array($prior,['Available','Repair'],true)?$prior:'Repair'),
        default=>'Repair',
    };
    db()->prepare('UPDATE equipment SET status=? WHERE id=?')->execute([$state,$equipment]);
    return $current;
}
function at_profit_groups(array $rows,string $key): array {
    $groups=[];
    foreach($rows as $r){$label=$r[$key];if(!isset($groups[$label]))$groups[$label]=array_fill_keys(['invoiced','received','cost','margin','unpriced_hours'],0.0);
        foreach($groups[$label] as $field=>$value)$groups[$label][$field]+=$r[$field];
    }
    ksort($groups);return $groups;
}
