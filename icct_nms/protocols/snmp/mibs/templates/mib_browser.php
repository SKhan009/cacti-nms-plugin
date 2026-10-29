<?php
$browser=icct_mib_browser_data($bundles);
$initialObject=$initialObject??null;
$icon=function($name){
    $paths=['search'=>'M21 21l-6-6M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0',
        'folder'=>'M3 6h7l2 3h9v11H3z','file'=>'M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 15h6M9 18h4',
        'exists'=>'M2 2h5v5H2zM17 17h5v5h-5zM10 4h8a4 4 0 0 1 0 8M14 20H6a4 4 0 0 1 0-8',
        'unsupported'=>'M2 2h5v5H2zM18 2h5v5h-5zM2 14h5v5H2zM7 4.5h11M7 16.5h2l6-12M17 17l6 6M23 17l-6 6',
        'partial'=>'M2 2h5v5H2zM17 17h5v5h-5zM10 4h8a4 4 0 0 1 0 8M14 20H6a4 4 0 0 1 0-8',
        'missing'=>'M2 2h5v5H2zM17 17h5v5h-5zM10 4h8a4 4 0 0 1 0 8M14 20H6a4 4 0 0 1 0-8M9 9h6v6H9z'];
    if($name==='exists'||$name==='partial'){
        $center=$name==='exists'?'<path d="m12 8 4 4-4 4-4-4z" fill="currentColor" stroke="none"/>':'<path d="m12 8 4 4-4 4-4-4z"/><path d="m12 8 4 4-4 4z" fill="currentColor" stroke="none"/>';
        return '<svg class="mib-browser-icon mib-source-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$paths[$name].'"/>'.$center.'</svg>';
    }
    return '<svg class="mib-browser-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$paths[$name].'"/></svg>';
};
$mibSelecting=$mibSelecting??false;
$renderTree=function($node,$depth=0)use(&$renderTree,$browser,$icon,$mibSelecting){
    foreach($node['children'] as $child){
        $hasChildren=(bool)$child['children'];
        if($hasChildren){echo '<details class="mib-browser-branch" open><summary>'.$icon('folder').'<span>'.icct_nms_h($child['label']).'</span></summary><div class="mib-browser-children">';}
        foreach($child['records'] as $id){$record=$browser['records'][$id]; ?>
        <?php if(!empty($mibSelecting)): ?><div class="mib-tree-select-row"><label class="mib-tree-checkbox"><input type="checkbox" data-tree-selection="<?= $id ?>" aria-label="Select <?= icct_nms_h($record['name']) ?>" <?= !$record['can_create']?'disabled':'' ?>></label><?php endif; ?>
        <button type="button" class="mib-browser-leaf" data-mib-node="<?= $id ?>" aria-pressed="false" title="<?= icct_nms_h($record['symbol'].' · '.$record['base_oid']) ?>"><?= $icon('file') ?><span><?= icct_nms_h($record['name']) ?></span><span class="mib-browser-state" data-source-state="<?= $record['source_state'] ?>" title="<?= icct_nms_h($record['source_status']) ?>" aria-label="<?= icct_nms_h($record['source_status']) ?>"><?= $icon($record['source_state']) ?></span></button><?php if(!empty($mibSelecting)): ?></div><?php endif; ?>
        <?php }
        $renderTree($child,$depth+1);
        if($hasChildren)echo '</div></details>';
    }
};
?>
<section class="mib-repository-browser" aria-label="MIB browser">
<aside class="mib-browser-sidebar" aria-label="MIB tree">
<label class="mib-browser-search"><?= $icon('search') ?><input type="search" id="mibBrowserSearch" aria-label="Search MIB name or OID" placeholder="Search With MIB Name or OID Number"></label>
<details class="mib-browser-root" open><summary>MIB Tree</summary><div id="mibBrowserTree"><?php $renderTree($browser['tree']); ?></div></details>
<div id="mibBrowserEmpty" class="mib-browser-empty mib-empty-state" <?= $browser['records']?'hidden':'' ?> role="status"><img src="protocols/snmp/mibs/assets/mib-empty.svg" width="98" height="60" alt=""><p><?= $browser['records']?'No matching MIB objects.':'No OIDs found. Upload a MIB file to view its info.' ?></p></div>
</aside>
<div class="mib-browser-detail is-empty">
<div id="mibBrowserPlaceholder" class="mib-empty-state" role="status"><img src="protocols/snmp/mibs/assets/mib-empty.svg" width="98" height="60" alt=""><p><?= $browser['records']?'Select an OID from the sidebar to view details.':'No OIDs found. Upload a MIB file to view its info.' ?></p></div>
<div id="mibBrowserContent" hidden>
<div class="mib-browser-detail-heading"><h2 id="mibBrowserName">Select an OID</h2><div class="mib-browser-detail-actions"><span id="mibBrowserBadge" class="mib-browser-badge" hidden><?= $icon('exists') ?><span></span></span><?php if($management&&empty($mibBrowserReadOnly)&&empty($mibSelecting)): ?><button type="button" class="button" id="mibBrowserCreate" disabled>Create</button><?php endif; ?></div></div>
<table class="mib-browser-properties" aria-label="Selected OID details"><tbody><?php foreach(['name'=>'Name','base_oid'=>'OID','module'=>'MIB','syntax'=>'Syntax','access'=>'Access','status'=>'Status','defval'=>'Defval','indexes'=>'Indexes','description'=>'Description'] as $key=>$label): ?><tr><th scope="row"><?= $label ?></th><td data-mib-property="<?= $key ?>">—</td></tr><?php endforeach; ?></tbody></table>
</div>
<?php foreach($browser['warnings'] as $warning): ?><p class="notice error"><?= icct_nms_h($warning) ?></p><?php endforeach; ?>
</div></section>
<script type="application/json" id="mibBrowserData" data-initial-object="<?= $initialObject===null?'':$initialObject ?>"><?= json_encode($browser['records'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
