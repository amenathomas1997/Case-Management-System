<?php
// Included by full_schema.php after v44_cases.php, against its synthetic database only:
// 4.5.0's lists once held in database enum columns (client types, note types, document
// categories, service and technology types, equipment lists), with the values the system
// relies on kept, and the iPhone app's form_options.
$cl=['client_types'=>implode("\n",CLIENT_TYPES),'note_types'=>implode("\n",NOTE_TYPES),'doc_categories'=>implode("\n",DOC_CATEGORIES),'vision_statuses'=>implode("\n",VISION_STATUSES),
    'closure_reasons'=>implode("\n",CLOSURE_REASONS),'referral_close_reasons'=>implode("\n",array_map(fn($k,$v)=>"$k | $v",array_keys(REFERRAL_CLOSE_REASONS),REFERRAL_CLOSE_REASONS))];
[$code,$out,$err]=$renderPage('lists',[]);
check($code===0 && str_contains($out,'Client types (built-in); always keeps VR, Pre-ETS') && str_contains($out,'Equipment conditions (items and loans)'),'The lists page offers the former fixed lists and says which values stay' . ($err ? ": $err" : ''));
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('lists',[],['save_lists'=>'clients']+array_replace($cl,['client_types'=>"VR\nPrivate"])));
check(cms_list_items('client_types')===CLIENT_TYPES && str_contains($sess,'Keep Pre-ETS'),'A value the system relies on cannot be removed (Pre-ETS), and the page says so');
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('lists',[],['save_lists'=>'clients']+array_replace($cl,['client_types'=>"VR\nPre-ETS\nany"])));
check(cms_list_items('client_types')===CLIENT_TYPES && str_contains($sess,'already uses any'),'A word the system uses for something else (Any) is refused');
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'clients']+array_replace($cl,['client_types'=>"VR\nPre-ETS\nPrivate client",'note_types'=>implode("\n",NOTE_TYPES)."\nHome visit"]));
check(cms_list_items('client_types')===['VR','Pre-ETS','Private client'] && in_array('Home visit',cms_list_items('note_types'),true),'A business adds its own client and note types' . ($err ? ": $err" : ''));
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Enum Synthetic Client','Active Training',1)"); $ec=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client_edit',['id'=>$ec],['name'=>'Enum Synthetic Client','status'=>'Active Training','client_type'=>'Private client']);
check($db->query("SELECT client_type FROM clients WHERE id=$ec")->fetchColumn()==='Private client','A new client type is saved on the record (the column is text since 4.5.0)' . ($err ? ": $err" : ''));
check(assemble_note(['note_type'=>'Home visit','subject'=>'s','body'=>'b'])['type']==='Home visit','A note can be filed under a new note type');
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'equipment','device_request_categories'=>implode("\n",DR_CATEGORIES),'equipment_categories'=>implode("\n",EQUIP_CATEGORIES)."\nAAC device",
    'equipment_conditions'=>"New\nLike new\nGood\nFair\nNeeds Repair",'maintenance_types'=>implode("\n",MAINT_TYPES)]);
check(in_array('Like new',cms_list_items('equipment_conditions'),true) && in_array('AAC device',cms_list_items('equipment_categories'),true),'A business adds equipment categories and conditions' . ($err ? ": $err" : ''));
$appItem=$call('add_equipment',['name'=>'Enum synthetic device','category'=>'AAC device','condition'=>'Like new']);
check($db->query("SELECT CONCAT(category,'|',item_condition) FROM equipment WHERE name='Enum synthetic device'")->fetchColumn()==='AAC device|Like new','Equipment keeps a new category and condition');
$fo=$call('form_options');
check(($fo['lists']['client_types'] ?? [])===['VR','Pre-ETS','Private client'] && in_array('Like new',$fo['lists']['equipment_conditions'] ?? [],true) && count($fo['lists']['score_labels'] ?? [])===5,'The iPhone app is sent the organisation\'s own lists');
$db->prepare("INSERT INTO client_notes(client_id,note_type,subject,body,author_id) VALUES(?,'Home visit','Visit','Synthetic home visit',1)")->execute([$ec]); $hvn=(int)$db->lastInsertId();
$renderPage('lists',[],['save_lists'=>'clients']+$cl+['client_types_reset'=>'1','note_types_reset'=>'1']);
$call('update_note',['id'=>$hvn,'subject'=>'Visit','body'=>'Synthetic home visit, edited']);
check($db->query("SELECT note_type FROM client_notes WHERE id=$hvn")->fetchColumn()==='Home visit','Editing a note keeps a note type since removed from the list');
set_setting('list_tech_types',json_encode(array_merge(TECH_TYPES,['AI'])));
check(tech_type_match('Uses a Braille Display daily')==='Braille Display' && tech_type_match('braille literacy')==='Braille Literacy' && tech_type_match('Needs AI tools')==='AI','Technology is matched as whole words, longest first');
set_setting('list_tech_types','');
$renderPage('lists',[],['save_lists'=>'equipment','device_request_categories'=>implode("\n",DR_CATEGORIES),'equipment_categories_reset'=>'1','equipment_categories'=>'','equipment_conditions_reset'=>'1','equipment_conditions'=>'','maintenance_types'=>implode("\n",MAINT_TYPES)]);
check(cms_list_items('client_types')===CLIENT_TYPES && cms_list_items('equipment_conditions')===EQUIP_CONDITIONS,'The built-in lists can be put back');
// Clean up the custom values: later checks re-run migrations from before 4.5.0, which turn these
// columns back into fixed lists (re-running those on a live site with custom values is not safe).
$db->exec("DELETE FROM client_notes WHERE id=$hvn"); $db->exec("UPDATE clients SET client_type='VR' WHERE id=$ec"); $db->exec("DELETE FROM equipment WHERE name='Enum synthetic device'");
