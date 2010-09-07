<html>
<head>
	<title>Analyse Plugin Package</title>
	<link rel="stylesheet" href="style.css" />
</head>
<body>
<form method="post" enctype="multipart/form-data">
	<label>Plugin ZIP file : <input type="file" name="package" /></label>
	<input type="submit" name="submit" value="Analyse!" />
</form>
<?php

error_reporting(E_ALL);
require '../plugin-meta.php';

if ( !empty($_FILES['package']['tmp_name']) ){
	$pluginMeta = getPluginPackageMeta($_FILES['package']['tmp_name']);
	unlink($_FILES['package']['tmp_name']);
	
?>
<div id="tabs">
<ul class="tab-buttons">
	<li><a href="#tabs-1">Output</a></li>
	<li><a href="#tabs-2">JSON</a></li>
	<li><a href="#tabs-3">Sections</a></li>
	<li><a href="#tabs-4">Section HTML</a></li>
</ul>

<div id="tabs-1" class="panel">
	<textarea><?php print_r($pluginMeta); ?></textarea>
</div>

<div id="tabs-2" class="panel">
	<textarea><?php echo json_encode($pluginMeta); ?></textarea>
</div>

<div id="tabs-3" class="panel">
<?php
if ( !empty($pluginMeta['sections']) ) {
	foreach($pluginMeta['sections'] as $sectionHeader => $sectionContent) {
		printf(
			"<h2>%s</h2>\n%s\n", 
			ucwords(str_replace('_', ' ', $sectionHeader)), 
			$sectionContent
		);
	}
} else {
	?>
	No sections found in readme.txt
	<?php
}
?>
</div>

<div id="tabs-4" class="panel">
<?php
if ( !empty($pluginMeta['sections']) ) {
	foreach($pluginMeta['sections'] as $sectionHeader => $sectionContent) {
		printf(
			"<h2>%s</h2><textarea>%s</textarea>", 
			ucwords(str_replace('_', ' ', $sectionHeader)),
			$sectionContent
		);
	}
} else {
	?>
	No sections found in readme.txt
	<?php
}
?>
</div>
</div>

<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.4/jquery-ui.min.js"></script>
<script type="text/javascript">
	jQuery(function(){
		$('#tabs').tabs();
	});
</script>

<?php 
}
?>

</body></html>