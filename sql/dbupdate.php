<#1>
<?php
$fields = array(
	'id' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => true
	),
	'user_id' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => true
	),
	'process_id' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => false
	),
	'start_date' => array(
		'type' => 'timestamp',
		'notnull' => true
	),
	'ref_ids' => array(
		'type' => 'text',
		'length' => 1024,
		'fixed' => false,
		'notnull' => true
	),
	'file_count' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => false
	),
	'total_bytes' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => false
	),
	'status' => array(
		'type' => 'text',
		'length' => 20,
		'fixed' => false,
		'notnull' => true
	),
	'progress' => array(
		'type' => 'integer',
		'length' => 4,
		'notnull' => false
	)
);
$ilDB->dropTable("ui_uihk_folddl_data", false);
$ilDB->createTable("ui_uihk_folddl_data", $fields);
$ilDB->addPrimaryKey("ui_uihk_folddl_data", array("id"));
?>
<#2>
<?php
$ilDB->createSequence("ui_uihk_folddl_data");
?>