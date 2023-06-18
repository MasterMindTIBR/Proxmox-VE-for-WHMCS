{if $vncreply}
<div class="row">
{$vncreply}
</div>
{/if}
<div class="row">
	<div style="text-align : left;">
	</div>
	<div class="col col-md-12">
		<div class="row">
			<div class="col col-md-3">
				<div class="row">
					<div class="col col-md-12">
						<img src="/modules/servers/pvewhmcs/img/{$vm_config['vtype']}.png"/>
					</div>
				</div>			
				<div class="row">
					<div class="col col-md-12">
						<img src="/modules/servers/pvewhmcs/img/os/{$vm_config['ostype']}.png"/>
					</div>
				</div>
			</div>
			<div class="col col-md-2">
				<img src="/modules/servers/pvewhmcs/img/{$vm_status['status']}.png"/><br/>
				<span style="text-transform: uppercase"><strong>{$vm_status['status']}</strong></span><br/>
				Up:&nbsp;{$vm_status['uptime']}
				
			</div>
			<div class="col col-md-7">
				<div class="row">
					<script src="/modules/servers/pvewhmcs/js/CircularLoader.js"></script>
					<div class="col col-md-3" style="height:106px;">
						<div id="c1" class="circle" data-percent="{$vm_status['cpu']}">CPU</div>
					</div>
					<div class="col col-md-3">
						<div id="c2" class="circle" data-percent="{$vm_status['memusepercent']}">RAM</div>
					</div>
					<div class="col col-md-3">
						<div id="c3" class="circle" data-percent="{$vm_status['diskusepercent']}">Disk</div>
					</div>
					<div class="col col-md-3">
						<div id="c4" class="circle" data-percent="{$vm_status['swapusepercent']}">Swap</div>
					</div>
				</div>
				<script>
				$(document).ready(function() {
					$('.circle').each(function(){
						$(this).circularloader({
							progressPercent: $(this).attr("data-percent"),
							fontSize: "13px",
							radius: 30,
							progressBarWidth: 8,
							progressBarBackground: "#D6B1F9",
							progressBarColor: "#802DBC",
						});
					});
				});
				</script>
			</div>
		</div>
	</div>

	<table class="table table-bordered table-striped">
		<tr>
			<td>Network Addressing</td><td>{$vm_config['ipv4']}&nbsp;Subnet Mask:&nbsp;{$vm_config['netmask4']}&nbsp;Gateway:&nbsp;{$vm_config['gateway4']}</td>
		</tr>
		<tr>
			<td>OS (Type)</td>
			<td>{$vm_config['ostype']}</td>
		</tr>
		<tr>
			<td>CPU (Spec)</td>
			<td>{$vm_config['sockets']}&nbsp;{$vm_config['cpu']}&nbsp;{$vm_config['cores']}&nbsp;core(s).</td>
		</tr>
		<tr>
			<td>Memory (RAM)</td>
			<td>{$vm_config['memory']}&nbsp;MB</td>
		</tr>
		<tr>
			<td>Network Interface #1</td>
			<td>{($vm_config['net0']|replace:',':'<br/>')}</td>
		</tr>
		<tr>
			<td>Network Interface #2</td>
			<td>{$vm_config['net1']}</td>
		</tr>
		<tr>
			<td>Storage (SSD/HDD)</td>
			<td>
			{$rootfs=(","|explode:$vm_config['rootfs'])}
			{$disk=(","|explode:$vm_config['ide0'])}
			{$disk[1]}
			{$rootfs[1]}
			{$vm_config['scsi0']}
			{$vm_config['virtio0']}
			</td>
		</tr>
	</table>
	{if ($smarty.get.a eq 'vmStat')}
	<h4>VM Statistics</h4>
	<ul class="nav nav-tabs client-tabs" role="tab-list">
		<li class="active"><a id="dailytab" data-toggle="tab" role="tab" href="#dailystat">Daily</a></li>
		<li><a id="dailytab" data-toggle="tab" role="tab" href="#weeklystat">Weekly</a></li>
		<li><a id="dailytab" data-toggle="tab" role="tab" href="#monthlystat">Monthly</a></li>
		<li><a id="dailytab" data-toggle="tab" role="tab" href="#yearlystat">Yearly</a></li>
	</ul>
	<div class="tab-content admin-tabs">
		<div id="dailystat" class="tab-pane active">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['day']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['day']}"/>
		</div>
		<div id="weeklystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['week']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['week']}"/>
		</div>
		<div id="monthlystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['month']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['month']}"/>
		</div>
		<div id="yearlystat" class="tab-pane">
			<img src="data:image/png;base64,{$vm_statistics['cpu']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['maxmem']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['netinout']['year']}"/>
			<img src="data:image/png;base64,{$vm_statistics['diskrw']['year']}"/>
		</div>
	</div>
	{/if}

	
</div>
