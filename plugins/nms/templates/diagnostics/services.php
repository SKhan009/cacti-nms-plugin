<?php if($workspace_section==='diagnosis' && $workspace_host) { ?>
<section class="nms-panel nms-service-panel"><h2 data-nms-tip="Tests run from the assigned collector against this device’s configured hostname. HTTPS verifies certificates. TCP checks only whether a connection opens. Each result saves the criteria used.">Application-service check</h2><p>Check a web page, DNS answer or TCP port.</p>
<form class="nms-service-form" method="post" action="<?php print nms_h(nms_workspace_url('diagnosis',$workspace_id)); ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="run_service_check">
<div class="nms-workspace-fields">
<label>Check type<select name="kind"><option value="http">HTTP response</option><option value="https">HTTPS response</option><option value="dns">DNS answer</option><option value="tcp">TCP connection only</option></select></label>
<label data-nms-tip="Default ports: HTTP 80, HTTPS 443, DNS 53. Enter the port used by your service.">Port<input type="number" name="port" min="1" max="65535" value="80" required></label>
<label>Timeout in seconds<input type="number" name="timeout" min="1" max="10" value="5" required></label>
</div>
<fieldset data-service-fields="web"><legend>Web response</legend><div class="nms-workspace-fields">
<label data-nms-tip="Path only, such as /health. Query parameters, credentials and authorization headers are not supported.">Path<input name="path" value="/" maxlength="1024"></label>
<label>Expected status<input type="number" name="expected_status" min="100" max="599" value="200"></label>
<label data-nms-tip="Optional text that must appear in the response. Do not enter secrets; criteria are saved with the result.">Expected text (optional)<input name="contains" maxlength="256"></label>
</div><p class="nms-service-note" data-nms-tip="Redirects are not followed. Responses larger than 64 KiB do not pass. HTTPS certificates must be valid.">The response must match these criteria.</p></fieldset>
<fieldset data-service-fields="dns"><legend>DNS answer</legend><div class="nms-workspace-fields">
<label data-nms-tip="Ask the selected device, acting as a DNS server, to resolve this name.">Query name<input name="name" placeholder="service.example.com" maxlength="253"></label>
<label>Record type<select name="record_type"><option>A</option><option>AAAA</option></select></label>
<label>Expected IP address<input name="expected_address" maxlength="45"></label>
</div></fieldset>
<div class="nms-service-actions"><button type="submit">Run service check</button></div></form></section>
<?php } ?>
<section class="nms-panel"><h2 data-nms-tip="Latest 50 requests from your account for accessible devices. Cancellation discards the result; an in-progress request ends within its timeout.">Service-check history</h2>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device / check</th><th>Execution / result</th><th>Requested / finished</th><th>Details</th></tr></thead><tbody>
<?php foreach($workspace_services as $service) { $criteria=json_decode($service['spec_json'],true)?:[];$result=json_decode($service['result_json'],true)?:[]; ?>
<tr><td><?php print nms_h($service['description']); ?><p><?php print nms_h(strtoupper($criteria['kind']??'').' · port '.($criteria['port']??'').' · collector '.$service['poller_id']); ?></p></td>
<td><?php print nms_h($service['status']); ?><?php if($result) { ?><p><?php print !empty($result['passed'])?'Criteria met':'Check did not pass'; ?></p><p><?php print nms_h($result['message']??''); ?></p><?php } ?></td>
<td><?php print nms_h($service['requested_at'].' / '.($service['finished_at']?:'Pending')); ?></td><td><details><summary>Criteria and result</summary><pre><?php print nms_h(json_encode(['criteria'=>$criteria,'result'=>$result],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details>
<?php if(in_array($service['status'],['queued','running'],true)) { ?><form method="post" action="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id)); ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="workspace_action" value="cancel_service_check"><input type="hidden" name="service_job_id" value="<?php print (int)$service['id']; ?>"><button type="submit">Cancel service check</button></form><?php } ?></td></tr>
<?php } if(!$workspace_services) { ?><tr><td colspan="4">No service checks in this selection.</td></tr><?php } ?>
</tbody></table></div></section>
