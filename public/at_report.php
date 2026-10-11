<?php
require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/at_services.php';
require_once __DIR__.'/inc/document.php';
require __DIR__.'/inc/layout.php';
$user=require_login();
try {
    $r=at_record((int)($_GET['id']??0),(int)$user['id']);
    if($r['kind']!=='trial')throw new DomainException('Choose a device comparison.');
    $client=at_client((int)$r['client_id'],(int)$user['id']);
    $detail=at_detail((int)$r['id'],(int)$user['id']);
    $doc=new AccessibleDocument('AT device comparison — '.$r['title']);
    $doc->paragraph(brand('org'));
    $doc->properties(['Client'=>$client['name'],'Status'=>$r['status'],'Prepared by'=>$user['name'],'Generated'=>date('Y-m-d')]);
    foreach(['goals'=>'Goals and success measures','context'=>'Tasks, environment and baseline'] as $key=>$label){$doc->heading($label);$doc->paragraph($r['details'][$key]??'');}
    // The recommended option is named in its own heading and at the start of the
    // rationale, so it is found by moving through the headings. Fields use the form's
    // labels, which carry each score's scale.
    $preferred=(int)($r['details']['preferred_option']??0);$preferredTitle=null;
    $labels=array_column(at_catalog()['trial_option']['fields'],'label','key');
    $doc->heading('Options compared');
    foreach($detail['children'] as $child){
        $isPreferred=$preferred&&$preferred===$child['id'];if($isPreferred)$preferredTitle=$child['title'];
        $doc->subheading($child['title'].' — '.$child['status'].($isPreferred?' (recommended)':''));$v=(array)$child['values'];$props=[];
        foreach(['device','tested_on','task_results','independence','ease','preference','cost','barriers'] as $key){$value=trim((string)($v[$key]??''));if($value!==''&&$key==='cost')$value=fmt_money((float)$value);$props[$labels[$key]]=$value!==''?$value:'Not recorded';}
        $doc->properties($props);
    }
    // Saved records hold every field, blank ones as '', so a blank value needs the fallback too.
    $doc->heading('Recommendation rationale');
    if($preferredTitle!==null)$doc->paragraph('Recommended option: '.$preferredTitle);
    $doc->paragraph(($r['details']['rationale']??'')!==''?$r['details']['rationale']:'No recommendation finalized.');
    $doc->paragraph('Scores are recorded staff observations, not a validated clinical assessment.');
    audit((int)$user['id'],'at.report','at_record',(int)$r['id']);
    $doc->download('at_comparison_'.$r['id'].'.html');
}catch(DomainException $e){
    // A full page (title, heading, message, way back) rather than a bare sentence.
    http_response_code(403);page_header('Error — Device comparison unavailable',$user,'at_services');
    echo '<h1>Device comparison unavailable</h1>'.error_notice($e->getMessage()).'<p class="page-actions"><a href="at_services.php?kind=trial"><span aria-hidden="true">&larr; </span>Back to device trial comparisons</a></p>';
    page_footer();
}
