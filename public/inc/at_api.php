<?php
declare(strict_types=1);
require_once __DIR__ . '/at_services.php';
try {
    if(!at_ready())fail('AT services require the 2.73.0 database migration.',503);
    $kind=(string)($_GET['kind']??$input['kind']??'');
    switch($action){
        case 'at_catalog':
            $items=[];foreach(at_catalog() as $key=>$def)if(at_can($def['perm']))$items[]=['id'=>$key,'label'=>$def['label'],'description'=>$def['description'],'statuses'=>$def['statuses']];
            out(['ok'=>true,'modules'=>$items]);
        case 'at_list':
            $def=at_definition($kind);$client=(int)($_GET['client_id']??0);
            out(['ok'=>true,'records'=>at_list($kind,$userId,$client),'fields'=>at_fields($kind,$userId,$client),'statuses'=>$def['statuses'],'description'=>$def['description']]);
        case 'at_detail':out(['ok'=>true]+at_detail((int)($_GET['id']??0),$userId));
        case 'at_ai_draft':
            if($_SERVER['REQUEST_METHOD']!=='POST')fail('Use POST for AI drafting.',405);
            require_once __DIR__.'/at_ai.php';
            if(!is_array($input['values']??null))fail('Invalid draft context.');
            $draft=at_ai_draft($kind,(string)($input['field']??''),$input['values'],$userId,(string)($input['instruction']??''),(int)($input['id']??0));
            // Never relay raw provider/cURL error text to the app.
            if(empty($draft['ok']))$draft['error']=api_ai_error((string)($draft['error']??''));
            out($draft);
        case 'at_homework_update':
            if($_SERVER['REQUEST_METHOD']!=='POST')fail('Use POST for changes.',405);
            require_once __DIR__.'/at_homework.php';
            at_homework_update((int)($input['id']??0),$input,$userId);out(['ok'=>true]);
        case 'at_issue_choices':
            $r=at_record((int)($_GET['id']??0),$userId);
            if(!at_can('equipment')||!in_array($r['kind'],['repair','reuse'],true))throw new DomainException('Equipment access is required.');
            $equipment=at_choices('equipment',$userId);
            if($r['kind']==='reuse')$equipment=array_values(array_filter($equipment,fn($o)=>(int)$o['value']===(int)$r['equipment_id']));
            else{
                // As the website's repair form: a loaner is offered only from items that are
                // Available now, never the device under repair, so a picker read by name
                // alone holds no item that issuing would then refuse.
                $available=array_flip(array_map('intval',db()->query("SELECT id FROM equipment WHERE status='Available'")->fetchAll(PDO::FETCH_COLUMN)));
                $equipment=array_values(array_filter($equipment,fn($o)=>(int)$o['value']!==(int)$r['equipment_id']&&isset($available[(int)$o['value']])));
            }
            // at_issue_clients(): the clients at_issue() accepts (a reuse device only to an open case).
            out(['ok'=>true,'clients'=>at_issue_clients($r,$userId),'equipment'=>$equipment]);
        case 'at_report':
            // A device comparison as plain text, with the same content as the website's
            // report (at_report.php), for the app to share. Generating it is audited there
            // too, so it is POST only, like every other recorded action.
            if($_SERVER['REQUEST_METHOD']!=='POST')fail('Use POST for reports.',405);
            $r=at_record((int)($input['id']??0),$userId);
            if($r['kind']!=='trial')throw new DomainException('Choose a device comparison.');
            $client=at_client((int)$r['client_id'],$userId);
            $detail=at_detail((int)$r['id'],$userId);
            $reportTitle='AT device comparison — '.$r['title'];
            $lines=[$reportTitle,brand('org'),'Client: '.$client['name'],'Status: '.$r['status'],'Prepared by: '.$userName,'Generated: '.date('Y-m-d')];
            foreach(['goals'=>'Goals and success measures','context'=>'Tasks, environment and baseline'] as $key=>$label)array_push($lines,'',$label,(string)($r['details'][$key]??''));
            // As the website: the recommended option is named in its own heading line and at
            // the start of the rationale; fields use the form's labels, which carry each
            // score's scale, and a blank is "Not recorded".
            $preferred=(int)($r['details']['preferred_option']??0);$preferredTitle=null;
            $labels=array_column(at_catalog()['trial_option']['fields'],'label','key');
            array_push($lines,'','Options compared');
            foreach($detail['children'] as $child){
                $isPreferred=$preferred&&$preferred===$child['id'];if($isPreferred)$preferredTitle=$child['title'];
                array_push($lines,'',$child['title'].' — '.$child['status'].($isPreferred?' (recommended)':''));$v=(array)$child['values'];
                foreach(['device','tested_on','task_results','independence','ease','preference','cost','barriers'] as $key){$value=trim((string)($v[$key]??''));if($value!==''&&$key==='cost')$value=fmt_money((float)$value);$lines[]=$labels[$key].': '.($value!==''?$value:'Not recorded');}
            }
            array_push($lines,'','Recommendation rationale');
            if($preferredTitle!==null)$lines[]='Recommended option: '.$preferredTitle;
            $lines[]=(string)($r['details']['rationale']??'')!==''?(string)$r['details']['rationale']:'No recommendation finalized.';
            array_push($lines,'','Scores are recorded staff observations, not a validated clinical assessment.');
            audit($userId,'at.report','at_record',(int)$r['id'],'mobile');
            out(['ok'=>true,'title'=>$reportTitle,'text'=>implode("\n",$lines)]);
        case 'at_matches':out(['ok'=>true,'matches'=>at_matches($_GET,$userId)]);
        case 'at_profitability':out(['ok'=>true,'rows'=>at_profitability($userId)]);
        case 'at_save':case 'at_issue':case 'at_return':
            if($_SERVER['REQUEST_METHOD']!=='POST')fail('Use POST for changes.',405);
            if($action==='at_save'){
                $values=$input['values']??[];if(!is_array($values))fail('Invalid field values.');
                $id=at_save($kind,array_merge($values,array_intersect_key($input,array_flip(['id','version','title','status']))),$userId);
                out(['ok'=>true,'id'=>$id]);
            }
            if($action==='at_issue')out(['ok'=>true,'id'=>at_issue((int)($input['id']??0),(int)($input['equipment_id']??0),(int)($input['client_id']??0),trim((string)($input['due_date']??''))?:null,$userId)]);
            at_return((int)($input['id']??0),$userId);out(['ok'=>true]);
        default:fail('Unknown AT service action.',404);
    }
}catch(DomainException $e){fail($e->getMessage(),400);}
// Logged without the message text and classified like every other action: bad
// input types answer 400, data the database refuses 422/409, real faults 500.
catch(Throwable $e){api_exception_out($e,'Could not complete this action. Your changes were not confirmed. Please reload and try again.');}
