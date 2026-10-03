<section id="device-ports" class="device-ports" hidden>
<h2>Port Config</h2>
<p>Physical port count comes from Device Type presets. Actual interface names and link status come from the device. In use means link up; Available means enabled with link down, which does not prove the port is unplugged.</p>
<p id="port-summary" role="status"></p><p id="port-observation"></p>
<p>Interfaces without reported physical-connector information are listed separately. Preset slots are not mapped to an interface until the device identifies its physical ports.</p>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Port / Interface</th><th>ifIndex</th><th>Type</th><th>Status</th><th>Description</th></tr></thead><tbody id="port-rows"></tbody></table></div>
<div class="port-pagination"><button type="button" class="button" id="ports-previous">Previous ports</button><span id="ports-page"></span><button type="button" class="button" id="ports-next">Next ports</button></div>
<script type="application/json" id="port-observations"><?= json_encode($id?icct_backend_ports_view($old):['ports'=>[],'fresh'=>false,'collected'=>0,'source'=>'','message'=>'Save the new device and its SNMP settings to start automatic port discovery.'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</section>
