<?php
$browser=icct_mib_browser_data($bundles);
$icon=function($name){
    $paths=['search'=>'M21 21l-6-6M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0',
        'folder'=>'M3 6h7l2 3h9v11H3z','file'=>'M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 15h6M9 18h4',
        'exists'=>'M4 4h5v5H4zM15 15h5v5h-5zM15 4h5v5h-5zM6 9v8h6M9 6h3',
        'missing'=>'M4 4h5v5H4zM6 9v8h5M15 14l6 7M21 14l-6 7M15 4h5v5h-5zM9 6h3'];
    return '<svg class="mib-browser-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$paths[$name].'"/></svg>';
};
$renderTree=function($node,$depth=0)use(&$renderTree,$browser,$icon){
    foreach($node['children'] as $child){
        $hasChildren=(bool)$child['children'];
        if($hasChildren){echo '<details class="mib-browser-branch" open><summary>'.$icon('folder').'<span>'.icct_nms_h($child['label']).'</span></summary><div class="mib-browser-children">';}
        foreach($child['records'] as $id){$record=$browser['records'][$id]; ?>
        <button type="button" class="mib-browser-leaf" data-mib-node="<?= $id ?>" aria-pressed="false" title="<?= icct_nms_h($record['symbol'].' · '.$record['base_oid']) ?>"><?= $icon('file') ?><span><?= icct_nms_h($record['name']) ?></span><span class="mib-browser-state" aria-label="<?= $record['created']?'Data source exists':'Data source not created' ?>"><?= $icon($record['created']?'exists':'missing') ?></span></button>
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
<p id="mibBrowserEmpty" class="mib-browser-empty" <?= $browser['records']?'hidden':'' ?>><?= $browser['records']?'No matching MIB objects.':'Upload a MIB file to browse its objects.' ?></p>
</aside>
<div class="mib-browser-detail">
<div class="mib-browser-detail-heading"><h2 id="mibBrowserName">Select an OID</h2><div class="mib-browser-detail-actions"><span id="mibBrowserBadge" class="mib-browser-badge" hidden><?= $icon('exists') ?><span></span></span><?php if($management): ?><button type="button" class="button" id="mibBrowserCreate" disabled>Create</button><?php endif; ?></div></div>
<table class="mib-browser-properties" aria-label="Selected OID details"><tbody><?php foreach(['name'=>'Name','base_oid'=>'OID','module'=>'MIB','syntax'=>'Syntax','access'=>'Access','status'=>'Status','defval'=>'Defval','indexes'=>'Indexes','description'=>'Description'] as $key=>$label): ?><tr><th scope="row"><?= $label ?></th><td data-mib-property="<?= $key ?>">—</td></tr><?php endforeach; ?></tbody></table>
<?php if($management): ?><a class="mib-browser-configure" id="mibBrowserConfigure" hidden>Configure Templates →</a><?php endif; ?>
<?php foreach($browser['warnings'] as $warning): ?><p class="notice error"><?= icct_nms_h($warning) ?></p><?php endforeach; ?>
</div></section>
<script type="application/json" id="mibBrowserData"><?= json_encode($browser['records'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
