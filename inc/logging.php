<h2><?php _e( 'Select plugins to log', 'pirate-parrot' ); ?></h2>
<form action="" method="post">
<?php
foreach ( $registered as $name ) {
	$checked = $allowed ? in_array( $name, $allowed ) : false;
?>
<input type="checkbox" id="<?php echo $name; ?>" name="allow_plugin[]" value="<?php echo $name; ?>" <?php echo $checked ? 'checked' : ''; ?>>
<label for="<?php echo $name; ?>"><?php echo $name; ?></label>
<?php
}
?>
		<?php wp_nonce_field( 'pp-allow', 'nonce' ); ?>
		<?php submit_button( __( 'Save', 'pirate-parrot' ), 'primary', 'pp-allow-plugins' ); ?>
</form>

<?php
if ( ! $allowed ) {
	return;
}
?>
<h2><?php _e( 'Select plugin to view log', 'pirate-parrot' ); ?></h2>
<form action="" method="post">
	<select id="pp_plugin_name" name="pp_plugin_name" onchange="this.form.submit()">
<?php
foreach ( $allowed as $name ) {
	$selected = isset( $_POST['pp_plugin_name'] ) && $name == $_POST['pp_plugin_name'] ? 'selected' : '';
?>
<option name="<?php echo $name; ?>" <?php echo $selected; ?>><?php echo $name; ?></option>
<?php
}
?>
	</select>

	<?php submit_button( __( 'Refresh', 'pirate-parrot' ), 'secondary', 'pp-view', false ); ?>
	<?php submit_button( __( 'Flush logs', 'pirate-parrot' ), 'secondary', 'pp-flush', false ); ?>

	<?php wp_nonce_field( 'pp-view', 'nonce' ); ?>

	<?php if ( $logs ) { ?>
	<?php submit_button( __( 'Download', 'pirate-parrot' ), 'secondary', 'pp-download', false ); ?>
	<?php } ?>
	<span id="pp-spinner" class="spinner" aria-hidden="true"></span>

</form>

<div id="pp-logs">
	<div id="pp-log-actions">
		<input type="radio" name="pp-log-type" value="all" id="pp-log-all"><label for="pp-log-all"><?php _e( 'All', 'pirate-parrot' ); ?></label>
		<?php foreach ( $this->get_log_types() as $type ) { ?>
		<input type="radio" name="pp-log-type" value="<?php echo $type; ?>" id="pp-log-<?php echo $type; ?>" <?php echo $type === 'info' ? 'checked' : ''; ?>><label for="pp-log-<?php echo $type; ?>"><?php echo ucwords( $type ); ?></label>
		<?php } ?>
	</div>

	<div id="pp-log-console">
	<?php
	if ( $logs ) {
		foreach ( $logs as $log ) {
			$style = 'info' !== $log['type'] ? 'display:none' : '';
	?>
	<div class="pp-log pp-log-<?php echo $log['type']; ?>" style="<?php echo $style; ?>">
		<span class="pp-log-timestamp"><?php echo $log['time']; ?></span>
		<span class="pp-log-type"><?php echo ucwords( $log['type'] ); ?></span>
		<span class="pp-log-msg"><?php echo basename( $log['file'] ); ?>:<?php echo $log['line']; ?> - <?php echo esc_html( $log['msg'] ); ?></span>
	</div>
	<?php
		}
	} else {
		_e( 'No logs found', 'pirate-parrot' );
	}
	?>
	</div>
</div>
