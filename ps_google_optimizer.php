<?php
/*
Plugin Name: Ps google website optimizer setting 
Plugin URI: http://www.web-strategy.jp/wp_plugin/ps-google-website-optimizer-setting/
Description: Set google website optimizer script.
Author: Kazuyuki Koshiba
Version: 0.9.5
Author URI: http://www.prime-strategy.co.jp
*/


$ps_google_optimizer =& new ps_google_optimizer();
load_plugin_textdomain( 'ps_google_optimizer', 'wp-content/plugins/ps-google-website-optimizer-setting/language' );

class ps_google_optimizer {

function __construct() {
	global $wp_version;
	register_activation_hook( __FILE__, array( &$this, 'ps_google_optimizer_install' ) );
	add_action( 'init', array( &$this, 'set_google_optimizer' ) );
	if( $this->compare_ver( $wp_version, '2.6.0' ) ) {
		add_action( 'admin_print_styles', array( &$this, 'ps_google_optimizer_admin_css' ) );
	}else{
		add_action( 'admin_head', array( &$this, 'ps_google_optimizer_admin_css' ) );
	}
}

function ps_google_optimizer() {
	$this->__construct();
}

function ps_google_optimizer_install() {
    global $wpdb;
	$wpdb->optimizer = $wpdb->prefix . "google_optimizer";
	$charset_collate = '';
	if ( $wpdb->supports_collation() ) {
		if ( !empty( $wpdb->charset ) ){
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		}
		if ( !empty( $wpdb->collate ) ){
			$charset_collate .= " COLLATE $wpdb->collate";
		}
	}

     $sql = 'CREATE TABLE ' . $wpdb->optimizer . " (
			pattern_id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			optimizer_original_url VARCHAR(200) NOT NULL,
			optimizer_name VARCHAR(200) NOT NULL,
			optimizer_url VARCHAR(200) NOT NULL,
			optimizer_content LONGTEXT NOT NULL,
			optimizer_memo TEXT NOT NULL,
			optimizer_state TINYINT NOT NULL DEFAULT '0',
			optimizer_date DATE NOT NULL
	) {$charset_collate};";
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}


function set_wysiwyg(){
	require_once( ABSPATH . 'wp-admin/admin.php');
	$parent_file = 'edit-pages.php';
	$editing = true;
	wp_enqueue_script('page');
	wp_enqueue_script('editor');
	add_thickbox();
	wp_enqueue_script('media-upload');
	wp_enqueue_script('word-count');
}

function set_google_optimizer() {

	if( $_GET['pattern_id'] ){
		add_action( 'admin_head', 'wp_tiny_mce' );
		add_action( 'admin_menu', array( &$this, 'set_wysiwyg' ) );
	}

	global $wpdb;
//$_SERVER['REQUEST_URI'] この関数ではサーバ内での文字列の比較のみ。xssは問題ないのでサニタイズ不要

	$this->load_optimizer_setting();
	add_action( 'admin_menu', array( &$this, 'add_optimizer_setting' ) );

	if( $_POST['psgo_submit'] ){
		add_action( 'admin_menu', array( &$this, 'update_optimizer_setting' ) );
	}elseif( $_POST['import_submit'] ){
		add_action( 'admin_menu', array( &$this, 'import_content' ) );
	}elseif( ( $_POST['pattern_submit'] || $_POST['state_submit'] ) && preg_match( '/^[0-9]+$/', $_POST[pattern_id] ) ){
		add_action( 'admin_menu', array( &$this, 'update_pattern_setting') );
	}elseif( $_POST['pattern_submit'] && preg_match( '/^new$/', $_POST['pattern_id'] ) ){
		add_action( 'admin_menu', array( &$this, 'insert_pattern_setting') );
	}elseif( $_POST['del_submit'] && preg_match( '/^[0-9]+$/', $_POST['pattern_id'] ) ){
		add_action( 'admin_menu', array( &$this, 'delete_pattern_setting' ) );
	}

	$this->testkeys = array();

//各ページスクリプト埋め込み実行
	foreach( $this->optimizer_setting as $key => $val ){
		if ( $_SERVER['REQUEST_URI'] == $val['psgo_original_url'] && $val['psgo_test_state'] == '0' ) {
			add_action( 'wp_head', array( &$this, 'insert_optimizer_control_script' ), 1 );
			$this->testkeys['control'][] = $key;
		}
		if( $val['psgo_test_type'] == 'ab' && $_SERVER['REQUEST_URI'] == $val['psgo_original_url'] && $val['psgo_test_state'] == '0' ){
			add_action( 'wp_footer', array( &$this, 'insert_optimizer_tracking_script'), 99 );
			$this->testkeys['tracking'][] = $key;
		}elseif( $val['psgo_test_type'] == 'ab' && $_SERVER['REQUEST_URI'] == $this->patterns[0]->optimizer_url && $val['psgo_original_url'] == $this->patterns[0]->optimizer_original_url && $val['psgo_test_state'] === '0' && $this->patterns[0]->optimizer_state === '0' && ( $this->patterns[0]->optimizer_date > date( "Y-m-d" ) || $this->patterns[0]->optimizer_date === '0000-00-00' ) ) {
			add_filter( 'the_content', array( &$this, 'the_content_change' ) );
			add_action( 'wp_footer', array( &$this, 'insert_optimizer_tracking_script' ), 99 );
			$this->testkeys['tracking'][] = $key;
		}elseif( $val['psgo_test_type'] == 'multi' && $_SERVER['REQUEST_URI'] == $val['psgo_original_url'] ){
			add_action( 'wp_footer', array( &$this, 'insert_optimizer_tracking_script' ), 99 );
			$this->testkeys['tracking'][] = $key;
		}
		if( $_SERVER['REQUEST_URI'] == $val['psgo_conversion_url'] && $val['psgo_test_state'] == '0' ) {
			add_action( 'wp_footer', array( &$this, 'insert_optimizer_conversion_script' ), 99 );
			$this->testkeys['conversion'][] = $key;
		}
	}
}

function the_content_change( $content ) {
	global $id;
	if( preg_match( '/<!--test_page-->/', $content ) ){
		return stripslashes( $this->patterns[0]->optimizer_content );
	}else{
		return $content;
	}

}

function load_optimizer_setting() {
//$_SERVER['REQUEST_URI'] この関数ではsqlを発行しているので、addslashes()
	$esc_request_uri = addslashes( $_SERVER['REQUEST_URI'] );

	global $wpdb;
	$this->optimizer_setting = get_option( 'ps_google_optimizer_options' );

	if( $_GET['optimaizer'] ){
		$select_sql = 'SELECT * FROM '.$wpdb->prefix.'google_optimizer WHERE `optimizer_original_url` =\''.$this->optimizer_setting[$_GET['optimaizer']]['psgo_original_url'].'\' AND `optimizer_state` < \'1\'';
	}else{
		$select_sql = 'SELECT * FROM '.$wpdb->prefix.'google_optimizer WHERE `optimizer_url` =\''.$esc_request_uri .'\' AND `optimizer_state` = \'0\'';
	}
	$this->patterns = $wpdb->get_results( $select_sql );
}

function add_optimizer_setting() {
	if( function_exists( 'add_options_page' ) ) {
		add_options_page( 'PS google website optimizer setting' /* page title */, 
		'Ps GWO setting' /* menu title */, 
		8 /* min. user level */, 
		basename(__FILE__) /* php file */ , 
		array( &$this, 'optimizer_setting' ) /* function for subpanel */
		);
	}
}

function update_optimizer_setting() {
    global $wpdb;
	check_admin_referer( 'test_update' );
	$post_data = $_POST;

	//必須項目の設定//
	$necessary_point = array( 'psgo_test_name', 'psgo_original_url' );

	//必須項目の確認
	foreach( $necessary_point as $necessary_val ){
		if( $post_data[$necessary_val] == '' ){
			$this->error_point[$necessary_val] = _c('please input', 'ps_google_optimizer' );
		}
	}

	//入力形式のチェック
	if( !preg_match('/^\//', $post_data['psgo_original_url'] ) ){
			$this->error_point['psgo_original_url'] = _c('not correct form', 'ps_google_optimizer' );
	}
	if( !preg_match('/^\//', $post_data['psgo_conversion_url'] ) ){
			$this->error_point['psgo_conversion_url'] = _c('not correct form', 'ps_google_optimizer' );
	}
	$i=0;
	foreach( $this->optimizer_setting as $val ){
		if( $post_data['psgo_original_url'] == $val['psgo_original_url'] ){
			$i++;
		}
	}
	if( $i > 1 ){
		$this->error_point['psgo_original_url'] = _c( 'The URL has already been registered.', 'ps_google_optimizer' );
	}

	if( is_array( $this->error_point ) ){
		$this->result_message = '<span class="error_message">'._c('Please input a required item. Please input it correctly.', 'ps_google_optimizer' ).'</span>';
	}else{
		if( $post_data['psgo_test_state'] == '-1' ){
			$table = $wpdb->prefix.'google_optimizer';
			$data = array( 'optimizer_state' => $post_data['psgo_test_state'] );
			$where = array( 'optimizer_original_url' => $post_data['psgo_original_url'] );
			$this->sql_count =	$wpdb->update( $table, $data ,$where );
		}
		$optimizer_options = array();
		$optimizer_options = get_option( 'ps_google_optimizer_options' );
		foreach ( $_POST as $key => $val ) {
			if( preg_match( '/^psgo_/', $key ) && $val != '' && ( ! in_array( $key , array( 'psgo_submit', 'psgo_test_id' ) ) ) ) {
				$optimizer_options[$_GET['optimaizer']][$key] = $val;
			}
		}
		if ( count( $optimizer_options ) ) {
			$update_optimizer_options_query = update_option( 'ps_google_optimizer_options', $optimizer_options );
		}
		if ( $update_optimizer_options_query || $this->sql_count ){
			$this->result_message = '<span class="success">'._c( 'The settings of Ps google website optimizer has changed successfully.', 'ps_google_optimizer' ).'</span>';
			$this->optimizer_setting = array();
			$this->load_optimizer_setting();
		}else{
			$this->result_message = '<span class="error_message">'._c( 'The settings has not been changed. There were no changes or failed to update the data base.', 'ps_google_optimizer' ).'</span>';
		}
	}
}

function insert_pattern_setting(){
    global $wpdb;
	$post_data = $_POST;
	check_admin_referer( 'update_pattern' );

	if ( !preg_match( '/^\/.*\?.+/', $post_data['optimizer_url'] ) ){
		$this->error_point['optimizer_url'] = _c('not correct form', 'ps_google_optimizer' );
	}
	$select_sql = 'SELECT pattern_id FROM '.$wpdb->prefix.'google_optimizer WHERE `optimizer_url` = \''.$post_data[optimizer_url].'\'';
	$count_same_url = count( $wpdb->get_results( $select_sql ) );
	if( $count_same_url > 0 ){
		$this->result_message = '<span class="error_message">'._c( 'The URL has already been registered.', 'ps_google_optimizer' ).'</span>';
		$this->error_point['psgo_original_url'] = _c( 'The URL has already been registered.', 'ps_google_optimizer' );
	}elseif( is_array( $this->error_point ) ){
		$this->result_message = '<span class="error_message">'._c('Please input a required item. Please input it correctly.', 'ps_google_optimizer' ).'</span>';
	}else{
		$last = array_splice( $post_data,6,6 );
		$optimizer_limit_date = $last['limit_year'].'-'.$last['limit_month'].'-'.$last['limit_day'];
		$post_data['optimizer_date'] = $optimizer_limit_date;
		$table = $wpdb->prefix.'google_optimizer';
		$this->sql_count = '0';
		$this->sql_count = $wpdb->insert( $table, $post_data );
		if( $this->sql_count ){
			$this->load_optimizer_setting();
			$this->result_message = '<span class="success">'._c( 'The variation page of Ps google website optimizer has added successfully.', 'ps_google_optimizer' ).'</span>';
		}else{
			$this->result_message = '<span class="error_message">'._c( 'Failed to add the variation page of Ps google website optimizer.', 'ps_google_optimizer' ).'</span>';
		}
	}
}

function update_pattern_setting(){
    global $wpdb;
	$post_data = $_POST;
	check_admin_referer( 'update_pattern' );

	if( $post_data['state_submit'] ){
	}else{
		if ( !preg_match( '/^\/.*\?.+/', $post_data['optimizer_url'] ) ){
			$this->error_point['optimizer_url'] = _c('not correct form', 'ps_google_optimizer' );
		}
		$select_sql = 'SELECT pattern_id FROM '.$wpdb->prefix.'google_optimizer WHERE `optimizer_url` = \''.$post_data[optimizer_url].'\' AND `pattern_id` != \''.$_GET['pattern_id'].'\'';
		$count_same_url = count( $wpdb->get_results( $select_sql ) );
	}
	if( $count_same_url > 0 ){
		$this->result_message = '<span class="error_message">'._c('The URL has already been registered.', 'ps_google_optimizer' ).'</span>';
		$this->error_point['psgo_original_url'] = _c('The URL has already been registered.', 'ps_google_optimizer' );
	}elseif( is_array( $this->error_point ) ){
		$this->result_message = '<span class="error_message">'._c('Please input a required item. Please input it correctly.', 'ps_google_optimizer' ).'</span>';
	}else{
		if( $post_data['state_submit'] ){
			$last = array_splice($post_data,2,3 );
		}else{
			$last = array_splice($post_data,6,6 );
			$optimizer_limit_date = $last['limit_year'].'-'.$last['limit_month'].'-'.$last['limit_day'];
			$post_data['optimizer_date'] = $optimizer_limit_date;
		}
		$table = $wpdb->prefix.'google_optimizer';
		$where = array( 'pattern_id'=> $post_data['pattern_id'] );
		$this->sql_count = '0';
		$this->sql_count = $wpdb->update( $table, $post_data ,$where);
		if( $this->sql_count ){
			$this->load_optimizer_setting();
			$this->result_message = '<span class="success">'._c('The variation page of Ps google website optimizer has changed successfully.', 'ps_google_optimizer' ).'</span>';
		}else{
			$this->result_message = '<span class="error_message">'._c('Failed to change the variation page of Ps google website optimizer.', 'ps_google_optimizer' ).'</span>';
		}
	}
}

function import_content(){
    global $wpdb;
	$post_data = $_POST;
	check_admin_referer( 'import' );
	$post_data['import_id'] = $this->ps_convert_kana( $post_data['import_id'] );
	$select_sql = 'SELECT post_content FROM '.$wpdb->prefix.'posts WHERE `ID` ='.$post_data['import_id'];
	$imports = $wpdb->get_results( $select_sql );
	$this->import_content = $imports[0]->post_content;

	if( $this->import_content ){
		$this->result_message = '<span class="success">'._c( 'It succeeded in import.', 'ps_google_optimizer' ).'</span>';
	}else{
		$this->result_message = '<span class="error_message">'._c( 'It failed in import.', 'ps_google_optimizer' ).'</span>';
	}
}

function delete_pattern_setting(){
    global $wpdb;
	$post_data = $_POST;
	check_admin_referer( 'update_pattern' );

	if( $post_data['optimizer_state'] == '1' ){
		$delete_sql = 'DELETE FROM `'.$wpdb->prefix.'google_optimizer` WHERE `pattern_id` = '.$post_data['pattern_id'].' LIMIT 1';
		$this->sql_count =	$wpdb->query( $delete_sql );
	}
	if ( $this->sql_count ){
		$this->result_message = '<span class="success">'._c('The variation page of Ps google website optimizer has deleted successfully.', 'ps_google_optimizer' ).'</span>';
	}else{
		$this->result_message = '<span class="error_message">'._c('Failed to delete the variation page of Ps google website optimizer.', 'ps_google_optimizer' ).'</span>';
	}

}
	

function optimizer_setting() {
//$_SERVER['REQUEST_URI'] この関数では表示まで行っているので、htmlspecialchars
	$esc_request_uri = htmlspecialchars( $_SERVER['REQUEST_URI'] );
	$esc_php_self = htmlspecialchars( $_SERVER['PHP_SELF'] );
	global $wpdb;
	$post_data = $_POST;
	?>
	<div class=wrap>
		<?php if ( function_exists( 'screen_icon' ) ) { screen_icon(); } ?>
		<h2>Ps google website optimizer setting</h2>
		<?php 
		if ( $_POST && is_numeric( $_GET['optimaizer'] ) ) {
			echo '<div style="background-color: rgb(255, 251, 204);" id="message" class="updated fade"><p>';
			echo $this->result_message.'<br></p></div>';
		}
			
		if( !$_GET['optimaizer']){ //main_page
		?>

		<h3><?php _e('List', 'ps_google_optimizer' ); ?></h3>
		<table class="widefat page fixed" cellspacing="0">
		<thead>
			<tr><th><?php _e('TEST NAME', 'ps_google_optimizer' ); ?></th><th><?php _e('ORIGINAL URL', 'ps_google_optimizer' ); ?></th><th><?php _e('MEMO', 'ps_google_optimizer' ); ?></th><th><?php _e('State', 'ps_google_optimizer' ); ?></th></tr>
		</thead>
		<tbody>
			<?php
			foreach( $this->optimizer_setting as $key => $val  ){
				echo '<tr><td><a href="'.$esc_request_uri.'&optimaizer='.$key.'">'.$val['psgo_test_name'].'</a></td><td>'.$val['psgo_original_url'].'</td><td>'.$val['psgo_test_memo'].'</td><td>';
				if( $val['psgo_test_state'] < 0 ){
					_e('stop', 'ps_google_optimizer');
				}else{
					_e('run', 'ps_google_optimizer');
				}
				echo '</td></tr>';
			}
			if( $this->optimizer_setting ){
				$next_count = count( $this->optimizer_setting ) +1;
			}else{
				$next_count = 1;
			}
			echo '</tbody></table><br />';
			echo '<a href="'.$esc_request_uri.'&optimaizer='.$next_count.'">'._c('create a new test', 'ps_google_optimizer' ).'</a>';

		}elseif( !$_GET['pattern_id'] ){ //pattern_page
		?>
		<a href="<?php echo $esc_php_self.'?page=ps_google_optimizer.php'; ?>"><?php _e('List', 'ps_google_optimizer' ); ?></a> &nbsp;&gt;&nbsp; <strong><?php _e('management of test', 'ps_google_optimizer' ); ?></strong>
			<form method="post" action="<?php echo $esc_request_uri; ?>">
				<?php wp_nonce_field( 'test_update' ); ?>
				<table class="form-table">
					<tr>
						<th><?php _e('State', 'ps_google_optimizer' ); ?></th>
						<td>
							<input type="radio" name="psgo_test_state" id="psgo_test_state_on" value="0"<?php if( is_array( $this->error_point ) && $post_data['psgo_test_state'] == '0' ){	echo 'checked="checked"'; }elseif($this->optimizer_setting[$_GET['optimaizer']]['psgo_test_state'] == '0'){	echo 'checked="checked"';}?> />
							<label for="psgo_test_state_on"><?php _e('Play', 'ps_google_optimizer' ); ?></label>
							<input type="radio" name="psgo_test_state" id="psgo_test_state_off" value="-1" <?php if( is_array( $this->error_point ) && $post_data['psgo_test_state'] == '-1' ){ echo 'checked="checked"'; }elseif( is_null( $this->error_point ) && ( $this->optimizer_setting[$_GET['optimaizer']]['psgo_test_state'] == '-1' || is_null( $this->optimizer_setting[$_GET['optimaizer'] ] ) ) ){ echo 'checked="checked"'; } ?> />
							<label for="psgo_test_state_off"><?php _e('Stop', 'ps_google_optimizer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php _e('Test Type', 'ps_google_optimizer' ); ?></th>
						<td>
							<input type="radio" name="psgo_test_type" id="psgo_test_type_ab" value="ab" checked="checked" />
							<label for="psgo_test_type_ab"><?php _e('A/B Experiment', 'ps_google_optimizer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php _e('Test name', 'ps_google_optimizer' ); ?><span class="error_message">*<?php _e('a required item', 'ps_google_optimizer' ); ?></span></th>
						<td>
							<input type="text" name="psgo_test_name" id="test_name" size="50" value="<?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_test_name'];	}else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_test_name']); } ?>" /><br />
							<span class="error_message"><?php echo $this->error_point['psgo_test_name']; ?></span>
						</td>
					</tr>
					<tr>
						<th><?php _e('Memo of this test', 'ps_google_optimizer' ); ?></th>
						<td>
							<input type="text" name="psgo_test_memo" id="memo" size="50" value="<?php if( is_array( $this->error_point ) ){	echo $post_data['psgo_test_memo']; }else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_test_memo']); }?>" />
						</td>
					</tr>
					<tr>
						<th><?php _e('Original url', 'ps_google_optimizer' ); ?><span class="error_message">*<?php _e('a required item', 'ps_google_optimizer' ); ?></span></th>
						<td>
							<?php if( is_null($this->optimizer_setting[$_GET['optimaizer']] ) ){ ?>
							 
							<input type="text" name="psgo_original_url" id="original_url" size="50" value="<?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_original_url']; } ?>" />
							<?php }else{ echo $this->optimizer_setting[$_GET['optimaizer']]['psgo_original_url']; ?>
							<input type="hidden" name="psgo_original_url" value="<?php echo $this->optimizer_setting[$_GET['optimaizer']]['psgo_original_url']; ?>" >
							<?php } ?>
							<br />
						<?php _e('Please input absolute path after the site URL. ex)/foo/bar.html', 'ps_google_optimizer' ); ?><br /><span class="error_message"><?php _e('Original Url is not possible to change again.', 'ps_google_optimizer' ); ?><?php echo $this->error_point['psgo_original_url']; ?></span>
						</td>
					</tr>
					<tr>
						<th><?php _e('Variation page url', 'ps_google_optimizer' ); ?></th>
						<td>
							<table>
							<?php
							foreach( $this->patterns as $key=> $val ){
								echo '<tr><td><a href="'.$esc_request_uri.'&pattern_id='.$val->pattern_id.'">'.$val->optimizer_name.'</a></td><td>URL:';
								echo $val->optimizer_url.'</td><td>[ <a href="'.$esc_request_uri.'&pattern_id='.$val->pattern_id.'">'._c( 'Edit', 'ps_google_optimizer' ).'</a> ]　';
								if( $val->optimizer_state == 0 ){
									echo '[ <a href="'.$esc_request_uri.'&pattern_id='.$val->pattern_id.'&state=-1">'._c('Stop', 'ps_google_optimizer' ).'</a> ]　';
								}else{
									echo '[ <a href="'.$esc_request_uri.'&pattern_id='.$val->pattern_id.'&state=0">'._c('Start', 'ps_google_optimizer' ).'</a> ]　';
								}
								echo '[ <a href="'.$esc_request_uri.'&pattern_id='.$val->pattern_id.'&state=1">'._c('Delete', 'ps_google_optimizer' ).'</a> ]</td></tr>';
								echo '<tr><td></td><td colspan="2">'.$val->optimizer_memo.'</td></tr>';
							}?>
							</table>
							<?php if( !$this->optimizer_setting[$_GET['optimaizer']]['psgo_original_url'] ){ ?>
							<?php _e('Original page URL is not set.<br />Please set original page URL. Afterwards, please set variation page url.', 'ps_google_optimizer' ); ?>
							<?php }else{ ?>
								<a href="<?php echo $esc_request_uri .'&pattern_id=new'; ?>"><?php _e('create a new variation page', 'ps_google_optimizer' ); ?></a>
								<?php
								if ( count($this->patterns) == 0 ){
									echo '<br /><span class="error_message">'._c('Please set variation page url.', 'ps_google_optimizer' ).'</span>';
								}
							} ?>
						</td>
					</tr>
					<tr>
						<th><?php _e('Conversion page url', 'ps_google_optimizer' ); ?><span class="error_message">*<?php _e('a required item', 'ps_google_optimizer' ); ?></span></th>
						<td><input type="text" name="psgo_conversion_url" id="conversion_url" size="50" value="<?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_conversion_url']; }else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_conversion_url']);}?>" /><br />
						<?php _e('Please input absolute path after the site URL. ex)/foo/bar.html', 'ps_google_optimizer' ); ?><span class="error_message"><?php echo $this->error_point['psgo_conversion_url']; ?></span>
						</td>
					</tr>
					<tr>
						<th><?php _e('Control Script', 'ps_google_optimizer' ); ?></th>
						<td><textarea name="psgo_control_script" id="control_script" cols="45" rows="3" ><?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_control_script']; }else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_control_script']); }?></textarea>
						</td>
					</tr>
					<tr>
						<th><?php _e('Tracking script', 'ps_google_optimizer' ); ?></th>
						<td><textarea name="psgo_tracking_script" id="tracking_script" cols="45" rows="3"><?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_tracking_script']; }else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_tracking_script'] ); }?></textarea>
						</td>
					</tr>
					<tr>
						<th><?php _e('Conversion Script', 'ps_google_optimizer' ); ?></th>
						<td><textarea name="psgo_conversion_script" id="conversion_script" cols="45" rows="3"><?php if( is_array( $this->error_point ) ){ echo $post_data['psgo_conversion_script']; }else{ echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_conversion_script']); }?></textarea>
						</td>
					</tr>
				</table>
				<div class="submit">
					<input type="submit" name="psgo_submit" class="button-primary" value="<?php _e('Save', 'ps_google_optimizer' ); ?>" />
				</div>
			</form>
		<?php }elseif( is_numeric( $_GET['state'] ) && !$this->sql_count ){ //state change ?> 
			<a href="<?php echo $esc_php_self.'?page=ps_google_optimizer.php'; ?>"><?php _e('List', 'ps_google_optimizer' ); ?></a> &nbsp;&gt;&nbsp; <a href="<?php echo $esc_php_self.'?page=ps_google_optimizer.php&optimaizer='.$_GET['optimaizer']; ?>"><?php _e('management of test', 'ps_google_optimizer' ); ?></a> &nbsp;&gt;&nbsp; <strong><?php _e('delete variation page', 'ps_google_optimizer' ); ?></strong>

			<?php if( $_GET['state'] > 0 ){ ?>
				<h3><?php _e('delete variation page', 'ps_google_optimizer' ); ?></h3>
				<?php _e('Do you really delete to delete this page.', 'ps_google_optimizer' ); ?>
				<form method="post" action="<?php echo $esc_request_uri; ?>">
					<input type="hidden" name="optimizer_state" value="<?php echo $_GET['state']; ?>" >
					<input type="hidden" name="pattern_id" value="<?php echo $_GET['pattern_id']; ?>" >
					<div class="submit">
						<input type="submit" name="del_submit" class="button-primary" value="<?php _e('Delete', 'ps_google_optimizer' ); ?>" />
					</div>
					<?php wp_nonce_field( 'update_pattern' ); ?>
				</form>
			<?php }elseif( $_GET['state'] < 0 ){ ?>
				<h3><?php _e('stop variation page', 'ps_google_optimizer' ); ?></h3>
				<?php _e('Do you really want to stop this page?', 'ps_google_optimizer' ); ?>
				<form method="post" action="<?php echo $esc_request_uri; ?>">
					<input type="hidden" name="optimizer_state" value="<?php echo $_GET['state']; ?>" >
					<input type="hidden" name="pattern_id" value="<?php echo $_GET['pattern_id']; ?>" >
					<div class="submit">
						<input type="submit" name="state_submit" class="button-primary" value="<?php _e('Stop', 'ps_google_optimizer' ); ?>" />
					</div>
					<?php wp_nonce_field( 'update_pattern' ); ?>
				</form>
			<?php }else{ ?>
				<h3><?php _e('start variation page', 'ps_google_optimizer' ); ?></h3>
				<?php _e('Do you really want to start this page?', 'ps_google_optimizer' ); ?>
				<form method="post" action="<?php echo $esc_request_uri; ?>">
					<input type="hidden" name="optimizer_state" value="<?php echo $_GET['state']; ?>" >
					<input type="hidden" name="pattern_id" value="<?php echo $_GET['pattern_id']; ?>" >
					<div class="submit">
						<input type="submit" name="state_submit" class="button-primary" value="<?php _e('Start', 'ps_google_optimizer' ); ?>" />
					</div>
					<?php wp_nonce_field( 'update_pattern' ); ?>
				</form>
			<?php } ?>

		<?php }else{ ?>
		<a href="<?php echo $esc_php_self.'?page=ps_google_optimizer.php'; ?>"><?php _e('List', 'ps_google_optimizer' ); ?></a> &nbsp;&gt;&nbsp; <a href="<?php echo $esc_php_self.'?page=ps_google_optimizer.php&optimaizer='.$_GET['optimaizer']; ?>"><?php _e('management of test', 'ps_google_optimizer' ); ?></a> &nbsp;&gt;&nbsp; <strong><?php _e('management of variation page', 'ps_google_optimizer' ); ?></strong>
		<h3><?php _e('Import', 'ps_google_optimizer' ); ?></h3>
		<form method="post" action="<?php echo $esc_request_uri; ?>">
			<table class="form-table">
				<tr>
					<th><?php _e('Page ID of import page', 'ps_google_optimizer' ); ?></th>
					<td>
					<select name="import_id">
						<option value=""> - </option>
						<?php 
							$select_id = 'SELECT ID,post_title FROM '.$wpdb->prefix.'posts WHERE `post_status` like \'publish\' ORDER by ID'; 
							$online_id = $wpdb->get_results( $select_id );
							foreach( $online_id as $val ){
								echo '<option value="'.$val->ID.'">'.$val->ID.' : '.$val->post_title.'</option>';
							}
						?>
					</select>
				</tr>
			</table>

			<div class="submit">
				<input type="submit" name="import_submit" class="button-primary" value="<?php _e('Import', 'ps_google_optimizer' ); ?>" />
			</div>
			<?php wp_nonce_field( 'import' ); ?>
		</form>
		<h3><?php _e('Edit of variation page', 'ps_google_optimizer' ); ?></h3>
		<form method="post" action="<?php echo $esc_request_uri; ?>">
			<?php
			if( preg_match( '/[^0-9|new]/', $_GET['pattern_id'] ) ){
				wp_die( 'unvalid post data exist.' );
			}
			$pattern_id = $_GET['pattern_id'];
			foreach( $this->patterns as $val ){
				if( $pattern_id == $val->pattern_id ){
					$optimizer_name = $val->optimizer_name;
					$optimizer_url = $val->optimizer_url;
					$optimizer_content = $val->optimizer_content;
					$optimizer_memo = $val->optimizer_memo;
					preg_match( '/([0-9]{4,4})-([0-9]{2,2})-([0-9]{2,2})/', $val->optimizer_date, $dates );
					$limit_year = $dates[1];
					$limit_month = $dates[2];
					$limit_day = $dates[3];
				}
			}
			?>
			<input type="hidden" name="pattern_id" value="<?php echo $pattern_id; ?>">
			<input type="hidden" name="optimizer_original_url" value="<?php echo stripslashes($this->optimizer_setting[$_GET['optimaizer']]['psgo_original_url']); ?>" /><br />
			<table class="form-table">
				<tr>
					<th><?php _e('Variation page name', 'ps_google_optimizer' ); ?></th>
					<td><input type="text" name="optimizer_name" id="" size="50" value="<?php if( is_array( $this->error_point ) ){ echo $post_data['optimizer_name']; }else{ echo $optimizer_name; } ?>" /></td>
				</tr>
				<tr>
					<th><?php _e('Variation page url', 'ps_google_optimizer' ); ?><span class="error_message">*<?php _e('a required item', 'ps_google_optimizer' ); ?></span></th>
					<td><input type="text" name="optimizer_url" id="" size="50" value="<?php if( is_array( $this->error_point ) ){ echo $post_data['optimizer_url']; }else{ echo $optimizer_url; } ?>" /><br />
					<?php _e('ex) /foo/?test ( Please add ? )', 'ps_google_optimizer' ); ?><span class="error_message"><?php echo $this->error_point['optimizer_url']; ?></span>
					</td>
				</tr>
				<tr>
					<th><?php _e('Content of variation page', 'ps_google_optimizer' ); ?></th>
					<td>

					<div id="poststuff" class="metabox-holder">
						<div id="post-body" class="has-sidebar">
							<div id="post-body-content" class="has-sidebar-content">
								<div id="postdivrich" class="postarea">
									<div id="editor-toolbar">
										<div class="zerosize">
											<input accesskey="e" type="button" onclick="switchEditors.go('optimizer_content')" />
										</div>
										<a id="edButtonHTML" class="active" onclick="switchEditors.go('optimizer_content', 'html');">HTML</a>
										<a id="edButtonPreview" onclick="switchEditors.go('optimizer_content', 'tinymce');"><?php _e('Visual', 'ps_google_optimizer' ); ?></a>
										<div id="media-buttons" class="hide-if-no-js">
											<a href="<?php bloginfo('wpurl'); ?>/wp-admin/media-upload.php?type=image&amp;TB_iframe=true" id="add_image" class="thickbox" title='<?php _e('Add an Image', 'ps_google_optimizer' ); ?>'><img src='<?php bloginfo('wpurl'); ?>/wp-admin/images/media-button-image.gif' alt='<?php _e('Add an Image', 'ps_google_optimizer' ); ?>' /></a>
											<a href="<?php bloginfo('wpurl'); ?>/wp-admin/media-upload.php?type=video&amp;TB_iframe=true" id="add_video" class="thickbox" title='<?php _e('Add Video', 'ps_google_optimizer' ); ?>'><img src='<?php bloginfo('wpurl'); ?>/wp-admin/images/media-button-video.gif' alt='<?php _e('Add Video', 'ps_google_optimizer' ); ?>' /></a>
											<a href="<?php bloginfo('wpurl'); ?>/wp-admin/media-upload.php?type=audio&amp;TB_iframe=true" id="add_audio" class="thickbox" title='<?php _e('Add Audio', 'ps_google_optimizer' ); ?>'><img src='<?php bloginfo('wpurl'); ?>/wp-admin/images/media-button-music.gif' alt='<?php _e('Add Audio', 'ps_google_optimizer' ); ?>' /></a>
											<a href="<?php bloginfo('wpurl'); ?>/wp-admin/media-upload.php?TB_iframe=true" id="add_media" class="thickbox" title='<?php _e('Add Media', 'ps_google_optimizer' ); ?>'><img src='<?php bloginfo('wpurl'); ?>/wp-admin/images/media-button-other.gif' alt='<?php _e('Add Media', 'ps_google_optimizer' ); ?>' /></a>
										</div>
									</div>
									<div id="quicktags">
										<?php wp_print_scripts( 'quicktags' ); ?>
										<script type="text/javascript">edToolbar()</script>
									</div>

									<div id='editorcontainer'>
										<textarea rows='30' cols='45' name='optimizer_content' tabindex='2' id='optimizer_content'><?php if( is_array( $this->error_point ) ){ echo stripslashes( $post_data['optimizer_content'] ) ; }elseif( $this->import_content ){ echo stripslashes($this->import_content); }else{ echo stripslashes( $optimizer_content ); } ?></textarea>
										<script type="text/javascript">
										// <![CDATA[
											edCanvas = document.getElementById('optimizer_content');
											var dotabkey = true;
											// If tinyMCE is defined.
											if ( typeof tinyMCE != 'undefined' ) {
												// This code is meant to allow tabbing from Title to Post (TinyMCE).
												jQuery('#title')[jQuery.browser.opera ? 'keypress' : 'keydown'](function (e) {
													if (e.which == 9 && !e.shiftKey && !e.controlKey && !e.altKey) {
													//	if ( (jQuery("#post_ID").val() < 1) && (jQuery("#title").val().length > 0) ) { autosave(); }
														if ( tinyMCE.activeEditor && ! tinyMCE.activeEditor.isHidden() && dotabkey ) {
															e.preventDefault();
															dotabkey = false;
															tinyMCE.activeEditor.focus();
															return false;
														}
													}
												});
											}
										// ]]>
										</script>
									</div>
								</div>
							</div>
						</div>
					</div>
					</td>
				</tr>
				<tr>
					<th><?php _e('MEMO', 'ps_google_optimizer' ); ?></th>
					<td><textarea name="optimizer_memo" id="" cols="45" rows="3" ><?php if( is_array( $this->error_point ) ){ echo $post_data['optimizer_memo']; }else{ echo $optimizer_memo; } ?></textarea></td>
				</tr>
				<tr>
					<th><?php _e('Time limit of this page', 'ps_google_optimizer' ); ?></th>
					<td>
					<select name="limit_year">
						<option value="0000">--</option>
						<?php
						for( $y = date("Y"); $y < date("Y")+3; $y++ ){ echo '<option value="'.$y.'" '; if( $post_data['limit_year'] && $y == $post_data['limit_year'] ){ echo 'selected="selected"'; }elseif( !$post_data['limit_year'] && $y == $limit_year ){ echo 'selected="selected"'; } echo '>'.$y.'</option>'; } ?>
					</select> / 
					<select name="limit_month">
						<option value="00">--</option>
						<?php for( $m = 1; $m <= 12; $m++ ){ echo '<option value="'.$m.'" '; 
						if( $post_data['limit_month'] && $m == $post_data['limit_month'] ){ echo 'selected="selected"'; }elseif( !$post_data['limit_month'] && $m == $limit_month ){ echo 'selected="selected"'; } echo '>'.$m.'</option>'; } ?>
					</select> / 
					<select name="limit_day">
						<option value="00">--</option>
						<?php for( $d = 1; $d <= 31; $d++ ){ echo '<option value="'.$d.'"';
						if( $post_data['limit_day'] && $d == $post_data['limit_day'] ){ echo 'selected="selected"'; }elseif( !$post_data['limit_day'] && $d == $limit_day ){ echo 'selected="selected"'; } echo '>'.$d.'</option>'; } ?>
					</select>
					</td>
				</tr>
			</table>
			<div class="submit">
				<input type="submit" name="pattern_submit" class="button-primary" value="<?php _e('Save', 'ps_google_optimizer' ); ?>" />
			</div>
			<?php wp_nonce_field( 'update_pattern' ); ?>
		</form>
		<?php } ?>

		<div class="ps_installation">
			<h3><?php _e('Usage', 'ps_google_optimizer' ); ?></h3>
			<ol>
				<li><?php _e('Create a new test in list page.', 'ps_google_optimizer' ); ?></li>
				<li><?php _e('Create a variation page in test magement page.', 'ps_google_optimizer' ); ?></li>
				<li><?php _e('Insert following code in the content area of original url page(or origin page). (<strong>Use HTML mode</strong>)', 'ps_google_optimizer' ); ?><br /><code>&lt;!--test_page--&gt;</code></li>
				<li><?php _e('Change a state in test magement page.', 'ps_google_optimizer' ); ?></li>
			</ol>
		</div>
	</div>
<?php
	}

function compare_ver( $target_ver, $base_ver ) {
	$target_ver_arr = explode( '.', $target_ver );
	$base_ver_arr = explode( '.', $base_ver );
	foreach( $base_ver_arr as $key => $figure ) {
		if ( $figure < $target_ver_arr[$key] ) {
			return true;
		} elseif ( $figure > $target_ver_arr[$key] ) {
			return false;
		}
	}
	return true;
}


function ps_google_optimizer_admin_css() {
	if( $_GET['page'] == 'ps_google_optimizer.php' ) { 
		if ( defined( 'WP_PLUGIN_URL' ) ) { 
			echo '<link rel="stylesheet" href="' . WP_PLUGIN_URL . str_replace( WP_PLUGIN_DIR, '', dirname( __file__ ) ) . '/css/ps_google_optimizer_admin.css" type="text/css" media="all" />' . "\n";
		} else {
			echo '<link rel="stylesheet" href="' . get_option('siteurl') . '/' . str_replace( ABSPATH, '', dirname( __file__ ) ) . '/css/ps_google_optimizer_admin.css" type="text/css" media="all" />' . "\n";
		}
	}     
} 

	
function insert_optimizer_control_script() {
	foreach( $this->testkeys['control'] as $val ){
		echo stripcslashes( $this->optimizer_setting[$val]['psgo_control_script'] )."\n";
	}
}
	
	
function insert_optimizer_tracking_script() {	
	foreach( $this->testkeys['tracking'] as $val ){
		echo stripcslashes( $this->optimizer_setting[$val]['psgo_tracking_script'] )."\n";
	}
}
	
	
function insert_optimizer_conversion_script() {
	foreach( $this->testkeys['conversion'] as $val ){
		echo stripcslashes( $this->optimizer_setting[$val]['psgo_conversion_script'] )."\n";
	}
}

function ps_convert_kana( $str ){
	$replace_after = array('1','2','3','4','5','6','7','8','9','0');
	$replace_before = array('１','２','３','４','５','６','７','８','９','０');
	$str = str_replace($replace_before, $replace_after, $str);
	return $str;
}


} //class end

