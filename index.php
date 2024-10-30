<?php
/*
Plugin Name: CdWPFlickr
Plugin URI: http://www.xidige.com
Description: Manager images in wordpress ,including Handles uploading, modifying images on Flickr, and insertion into posts.
 上传和编辑 Flickr 账户中的图片，并且在博文中插入Flickr账户中的图片，利用Flickr做图床。
 在WPFlickr的基础上，修改了授权验证方式、去掉部分代码；把原先依赖的phpflickr换成了cdflickr（这个是基于phpflickr修改而来的）
Version: 1.0.0
Author: cidy0106
Author URI: http://www.xidige.com
*/ 

/**
 * 初始化从数据库加载oauth信息（如果存在）；
 * 否则开始授权流程
 */
if(version_compare(PHP_VERSION, '4.4.0') < 0) 
	wp_die(sprintf(__('You are currently running %s and you must have at least PHP 4.4.x in order to use Flickr Manager!', 'flickr-manager'), PHP_VERSION));

define('WFM_OVERLAY_DIR','overlays');

if(!class_exists('FlickrManager')) :
if (session_id () == "") {
	@session_start ();
}
// Load Dependencies
require_once(dirname(__FILE__) . '/lib/inc.cdflickr.php');
require_once(dirname(__FILE__) . '/BasePlugin.php');

class FlickrManager extends BasePlugin
{
	var $flickr;
	var $cache_table;
	var $cache_dir;
	var $token_table;//存放flickr的oauthtoken
	
	function FlickrManager() 
	{
		parent::__construct();
		
		$this->ApplyDefaults();
		$this->CreateFlickrHandler($this->settings['api_key'], $this->settings['secret']);
		
		add_action('init', array(&$this, 'RegisterWidgets'));
		
		//Additional links on the plugin page
		add_filter('plugin_row_meta', array(&$this, 'RegisterPluginLinks'),10,2);
		
		// Clear previous version cache
		register_activation_hook( __FILE__, array(&$this, 'ClearCache') );
		
		// Clean up after our self
		register_deactivation_hook( __FILE__, array(&$this, 'ClearCache') );
		
		// Register the photo shortcodes
		$this->CreateShortcodes();
		
		if(!is_admin() && !empty($this->settings['photo_share'])) {
			$this->EnablePhotoSharing();
		}
	}
	
	function ApplyDefaults() 
	{
		$defaults = array(
			// update by www.xidige.com on 2014.09.19
			//'proxy_server' =>'127.0.0.1',
			//'proxy_port'=>8087,
			'api_key' => 'dfd2b016a0f46b0303dd27a5505b7c96'
			,'secret' => '672926d3ba347eff'
			,'per_page' => 5
            ,'cache' => 'db'
            ,'recent_widget' => array(
									'title' => __('Recent Photos', 'flickr-manager')
									,'photos' => 10
									,'viewer' => (!empty($this->settings['lightbox_default'])) ? $this->settings['lightbox_default'] : ''
								)
		);
		
		foreach($defaults as $k => $v) {
			//if(empty($this->settings[$k])) {
				$this->settings[$k] = $v;
			//}
		}
		
		global $wpdb;
		$this->cache_table = $wpdb->prefix . "flickr";
		$this->cache_dir = dirname(__FILE__) . '/cache/';
		
		$this->token_table = $wpdb->prefix. 'flickr_token';
		$this->createFlickrTable();
	} 
	function createFlickrTable(){
		global $wpdb;
		
		//这里使用apikey作为索引，为了只存在一个记录
		$query = " CREATE TABLE IF NOT EXISTS `$this->token_table` (
		`id` INT( 11 ) NOT NULL  AUTO_INCREMENT PRIMARY KEY,
		`api_key` VARCHAR( 100 ) NOT NULL ,
		`oauth_token` VARCHAR( 100 )  ,
		`oauth_token_secret` VARCHAR( 100 ) ,
		`user_nsid` VARCHAR( 100 )  ,
		`username` VARCHAR( 100 )  ,
		`createtime` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ,
		UNIQUE  INDEX idx_flickr_token_apikey(api_key) 
		) ";
		
		$wpdb->query($query);
		$wpdb->query($wpdb->prepare("insert into `$this->token_table` (api_key)
				 values(%s)  on duplicate key update api_key=values(api_key)",
				$this->settings['api_key']));
	}
	function afterRequestCode($token,$token_secret){
		$_SESSION['request_oauth_token']=$token;
		$_SESSION['request_oauth_token_secret']=$token_secret;
	}
	function saveAccessToken($flickr_oauth){
		global $wpdb;
		
		foreach($flickr_oauth as $k => $v) {
			//if(empty($this->settings[$k])) {
				$this->settings[$k] = $v;
			//}
		}		
		$wpdb->query($wpdb->prepare("insert into `$this->token_table` (api_key,oauth_token,oauth_token_secret,user_nsid,username)
				 values(%s,%s,%s,%s,%s) on duplicate key update 
				oauth_token=values(oauth_token) ,oauth_token_secret=values(oauth_token_secret),user_nsid=values(user_nsid),username=values(username) ",
            $this->settings['api_key'],
            $flickr_oauth['oauth_token'],
            $flickr_oauth['oauth_token_secret'],
            $flickr_oauth['user_nsid'],
            $flickr_oauth['username']));
	}
	function getAccessToken(){
		global  $wpdb;
		return $wpdb->get_row($wpdb->prepare("select * from `$this->token_table` where api_key=%s"
				, $this->settings['api_key']),ARRAY_A);		
	}
	function CreateFlickrHandler($api_key, $secret) 
	{
		global $wpdb;
		
		$this->flickr = new CdFlickr($api_key, $secret);
		if (!(empty($this->settings['proxy_server']) || empty($this->settings['proxy_port']))) {
			 $this->flickr->setProxy($this->settings['proxy_server'], $this->settings['proxy_port']);;
		}
		
		
        if($this->settings['cache'] == 'fs') {
        	// Enable file system caching
            $this->flickr->enableCache('fs', $this->cache_dir);
        } else {
        	// Enable database caching
            if ( isset($wpdb->charset) && !empty($wpdb->charset) ) {
				$charset = ' DEFAULT CHARSET=' . $wpdb->charset;
			} elseif ( defined(DB_CHARSET) && DB_CHARSET != '' ) {
				$charset = ' DEFAULT CHARSET=' . DB_CHARSET;
			} else {
				$charset = '';
			}
			
            $query = " CREATE TABLE IF NOT EXISTS `$this->cache_table` (
							`request` CHAR( 35 ) NOT NULL ,
							`response` MEDIUMTEXT NOT NULL ,
							`expiration` DATETIME NOT NULL ,
							INDEX ( `request` )
						) " . $charset;
            
            $wpdb->query($query);
            
            $this->flickr->enableCache('custom', array(array(&$this, 'CacheGet'), array(&$this, 'CacheSet')));
        }
// 		$this->flickr->setToken($this->GetSetting('token'));
		//从数据库读取oauth相关信息，初始化flickr（如果有的话）
		$flickr_oauth=$this->getAccessToken();
		if(!empty($flickr_oauth['oauth_token'])){
			if (!empty($flickr_oauth['oauth_token'])){
				$this->flickr->setToken($flickr_oauth['oauth_token']);
				$this->settings['token'] = $flickr_oauth['oauth_token'];
			}
			if (!empty($flickr_oauth['oauth_token_secret'])){
				$this->flickr->token_secret = $flickr_oauth['oauth_token_secret'];
			}
			if (!empty($flickr_oauth['user_nsid'])){
				$this->settings['nsid'] = $flickr_oauth['user_nsid'];
			}
			if (!empty($flickr_oauth['username'])){
				$this->settings['username'] = $flickr_oauth['username'];
			}
			foreach($flickr_oauth as $k => $v) {
				if(empty($this->settings[$k])) {
					$this->settings[$k] = $v;
				}
			}
		}		
	}
	
	function ClearCache() {
		global $wpdb;
		
		$wpdb->query("DROP TABLE `$this->cache_table`");
		
		if ($dir = opendir($this->cache_dir)) {
			while ($file = readdir($dir)) {
				if (substr($file, -6) == '.cache') {
					unlink($this->cache_dir . '/' . $file);
				}
			}
		}
	}
	
	function CacheGet($key) {
		global $wpdb;
		$result = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$this->cache_table` WHERE request = %s AND expiration >= NOW()"
											, $key),ARRAY_A);
		
		if ( is_null($result) ) return false;
		return $result->response;
	}
	
	function CacheSet($key, $value, $expire) {
		global $wpdb;
		$query ="
			INSERT INTO `$this->cache_table`
				(
					request, 
					response, 
					expiration
				)
			VALUES
				(
					%s,%s, 
					FROM_UNIXTIME(%d)
				)
			ON DUPLICATE KEY UPDATE 
				response = VALUES(response),
				expiration = VALUES(expiration)";
		$wpdb->query($wpdb->prepare($query, $key,$value, (time() + (int) $expire) ));
	}
	
	function CreateShortcodes()
	{
		
		add_shortcode('flickr', array(&$this, 'RenderFlickrShortcode'));
		add_shortcode('flickrset', array(&$this, 'RenderFlickrsetShortcode'));
		
	}
	
	function EnablePhotoSharing() {
		
		add_action('init', array(&$this, 'LoadSharingJavascript'));
		add_action('wp_head', array(&$this, 'RenderPhotoSharing'));
		
	}
	
	function LoadSharingJavascript() {
		
		wp_enqueue_script('jquery-sharing',plugins_url('/js/jquery.share.js', __FILE__), array('jquery'));
		wp_enqueue_script('wfm-sharing',plugins_url('/js/wfm-share.js', __FILE__), array('jquery'));
	
	}
	
	function RegisterWidgets()
	{
		// Register recent photo's widget
		//if(function_exists('register_sidebar_widget'))
		//	register_sidebar_widget('Recent Flickr Photos', array(&$this, 'RenderRecentPhotoWidget'));
        	// update by carey zhou on 2012.01.02
		if(function_exists('wp_register_sidebar_widget'))
			wp_register_sidebar_widget('Recent_Flickr_Photos_ID_1', 'Recent Flickr Photos', array(&$this, 'RenderRecentPhotoWidget'));	
			
		//if(function_exists('register_widget_control'))
		//	register_widget_control ( 'Recent Flickr Photos', array(&$this, 'RenderRecentPhotoWidgetControl'));
        	// update by carey zhou on 2012.01.02
		if(function_exists('wp_register_widget_control'))
			wp_register_widget_control ('Recent_Flickr_Photos_ID_1', 'Recent Flickr Photos', array(&$this, 'RenderRecentPhotoWidgetControl'));
	}
	
	
	function RegisterPluginLinks($links, $file) {
		$base = $this->GetBaseName();
		
		if($file == $base) {
			$links[] = sprintf('<a href="%s">%s</a>', admin_url("/options-general.php?page=" . $base), __('Settings','flickr-manager'));
			$links[] = '<a href="http://www.xidige.com/" title="Support">Support</a>';
			$links[] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=cidy0106%40gmail%2ecom&amp;lc=US&amp;currency_code=USD">Donate</a>';
		}
		
		return $links;
	}
	
	function RenderPhotoSharing()
	{
		$services = sprintf("['%s']",implode("','", $this->settings['photo_share']));
		echo "<script type='text/javascript'>\n//<![CDATA[\n";
		echo sprintf("var WFM_ShareServices = %s;\n", $services);
		echo "//]]>\n</script>";
	}
	
	function GetPhotos($page, $owner, $filter = null) 
	{
		$params = array(
			'extras' => 'license,owner_name,original_format'
			,'per_page' => $this->settings['per_page']
			,'page' => $page
			//,'auth_token' => $this->settings['token']
			,'text' => $filter
			,'user_id' => $owner
			,'media' => (!empty($owner)) ? 'all' : 'photos'
		);
		
		if($owner != null && !empty($this->settings['privacy_filter']) && $this->settings['privacy_filter'] == 'true')
			$params['privacy_filter'] = 1;
		
		// Disable caching incase of new photos
		$this->flickr->request("flickr.photos.search", $params, true);
		return $this->flickr->parsed_response ? $this->flickr->parsed_response['photos'] : false;
	}
	
	function SavePhoto($photoid, $title, $description, $tags) 
	{
		if(empty($photoid)) return 0;
		
		$this->flickr->photos_setMeta($photoid, stripcslashes($title), stripcslashes($description));
		$this->flickr->photos_setTags($photoid, $tags);
		
		return $photoid;
	}
	
	function GetBaseName() 
	{
		return plugin_basename(__FILE__);
	}
	
	function GetSignature($params) {
		ksort($params);
		
		$api_sig = $this->settings['secret'];
		
		foreach ($params as $k => $v){
			$api_sig .= $k . $v;
		}
		
		return md5($api_sig);
	}
	
	function RenderFlickrShortcode($args) {
		$photo = $this->flickr->photos_getInfo($args['id']);
		$photo = $photo['photo'];
		
		$rel = '';
		if($args['overlay'] == 'true') {
			if(empty($args['group'])) {
				$rel = 'flickr-mgr';
			} else {
				$rel = sprintf('flickr-mgr[%s]', $args['group']);
			}
		}
		
		$markup = '';
		
		if($photo['media'] == 'video' && in_array($args['thumbnail'], array('video_player','site_mp4'))) {
			$markup = $this->RenderVideo($args['id'], ($args['thumbnail'] == 'site_mp4') ? 'html5': 'flash');
		} else {
			$url = $photo['urls']['url'][0]['_content'];
			$original = ($args['size'] == 'original') ? $this->flickr->buildPhotoURL($photo, 'original') : '';
			
            $class = ($args['overlay'] == 'true') ? sprintf('flickr-%s', $args['size']) : '';
            if(!empty($args['align']) && $args['align'] != 'none') 
                $class .= " align" . $args['align'];
            
			if(in_array($args['size'], array('video_player','site_mp4','mobile_mp4'))) {
				
				$sizes = $this->flickr->photos_getSizes($args['id']);
				foreach($sizes as $size) {
					if(strtolower(str_replace(' ', '_', $size['label'])) == $args['size'] && $args['size'] == 'mobile_mp4') {
						$url = $size['source'];
						$rel = '';
						break;
					} elseif(strtolower(str_replace(' ', '_', $size['label'])) == $args['size']) {
						$original = $size['source'];
						$rel = (!empty($rel)) ? 'flickr-mgr' : '';
						break;
					}
				}
			} 
			
			
			$settings = array(
				'url' => $url
				,'title' => $photo['title']
				,'rel' => $rel
				,'thumbnail' => $this->flickr->buildPhotoURL($photo, $args['thumbnail'])
				,'class' => $class
				,'description' => $photo['description']
				,'original' => $original
			);
			
			$markup = $this->RenderPhoto($settings);
			
			if($photo['owner']['nsid'] != $this->settings['nsid']) {
				$licenses = $this->flickr->photos_licenses_getInfo();
				
				foreach($licenses as $license) {
					if($license['id'] == $photo['license']) {
						$markup .= sprintf('<br /><small id="license-%s"><a href="%s" title="%s" rel="license" onclick="return false;"><img src="%s" alt="%s" /></a> 
									by %s</small>'	, $photo['id']
													, $license['url']
													, $license['name']
													, plugins_url('/images/creative_commons_bw.gif', __FILE__)
													, $license['name']
													, $photo['owner']['username']);
					}
				}
			}
		}
		
		return $markup;
	}
	
	function RenderFlickrsetShortcode($args) 
	{
		$priv = ($this->settings['privacy_filter'] == 'true') ? 1 : null;
		$photoset = $this->flickr->photosets_getPhotos($args['id'], 'original_format,description', $priv, $args['photos']);
		
		$markup = '';
		foreach ($photoset['photoset']['photo'] as $photo) {
			$settings = array(
				'url' => sprintf('http://www.flickr.com/photos/%s/%s/',$photoset['photoset']['owner'],$photo['id'])
				,'title' => $photo['title']
				,'rel' => ($args['overlay'] == 'true') ? sprintf('flickr-mgr[%s]',$args['id']) : ''
				,'thumbnail' => $this->flickr->buildPhotoURL($photo, $args['thumbnail'])
				,'class' => ($args['overlay'] == 'true') ? sprintf('flickr-%s', $args['size']) : ''
				,'description' => $photo['description']
				,'original' => ($args['size'] == 'original') ? $this->flickr->buildPhotoURL($photo, 'original') : ''
			);
			
			$markup .= $this->RenderPhoto($settings);
		}
		
		return sprintf('<div class="flickrGallery">%s</div>', $markup);
	}
	
	function RenderPhoto($info) 
	{
		$markup = $this->settings['before_wrap'];
		
        	//update by carey zhou on 2012/02/11 for remove a tag
		//$markup .= sprintf('<a href="%s" title="%s" rel="%s" class="flickr-image">'
		//						, htmlspecialchars($info['url'])
		//						, htmlspecialchars($info['title'])
		//						, $info['rel']);
		
		$markup .= sprintf('<img src="%s" alt="%s" class="%s" title="%s" longdesc="%s" />'
								, $info['thumbnail']
								, htmlspecialchars($info['title']["_content"])
								, $info['class']
								, htmlspecialchars($info['description']["_content"])
								, $info['original']);
		
        	//update by carey zhou on 2012/02/11 for remove a tag
		//$markup .= '</a>';
        
        	$markup .= $this->settings['after_wrap'];
		
		return $markup;
	}
	
	function RenderVideo($vid, $type = 'flash', $sizes = null) {
		if(empty($sizes)) {
			$sizes = $this->flickr->photos_getSizes($vid);
		}
		
		if($type == 'html5') {
			
			$video = array();
			foreach($sizes as $v) {
				if($v['label'] == 'Site MP4') {
					$video = $v;
					break;
				}
			}
			
			return sprintf('<video width="%s" height="%s" controls><source src="%s" type="video/mp4">%s</video>'
							, $video['width']
							, $video['height']
							, $video['source']
							, $this->RenderVideo($vid, 'flash', $sizes));
			
		} else {
		
			$video = array();
			foreach($sizes as $v) {
				if($v['label'] == 'Video Player') {
					$video = $v;
					break;
				}
			}
			
			return sprintf('<object width="%s" height="%s" data="%s" type="application/x-shockwave-flash" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000">
								<param name="flashvars" value="flickr_show_info_box=false"></param>
								<param name="movie" value="%s"></param>
								<param name="allowFullScreen" value="true"></param>
							</object>', $video['width'], $video['height'], $video['source'], $video['source']);
								
		}
	}
	
	function RenderRecentPhotoWidget($args) 
	{
		$settings = $this->settings['recent_widget'];
		
		extract($args);
		
		$title = '';
		if(!empty($settings['title'])) {
			$title = sprintf('%s<a href="http://www.flickr.com/photos/%s/"><img src="%s" border="0" alt="Flickr" /></a> %s%s'
								,$before_title
								,$this->settings['nsid']
								,plugins_url('/images/flickr-media.gif', __FILE__)
								,$settings['title']
								,$after_title);
		}
		
		$photos = $this->flickr->people_getPublicPhotos($this->settings['nsid'], null, 'icon_server,original_format', $settings['photos'], 1);
		
		$markup = '';
		$rel = (!empty($settings['viewer'])) ? 'flickr-mgr[recent]' : '';
		
		foreach ($photos['photos']['photo'] as $photo) {
			$settings = array(
				'url' => sprintf('http://www.flickr.com/photos/%s/%s/',$photo['owner'],$photo['id'])
				,'title' => $photo['title']
				,'rel' => $rel
				,'thumbnail' => $this->flickr->buildPhotoURL($photo, 'square')
				,'class' => (!empty($settings['viewer'])) ? sprintf('flickr-%s', $settings['viewer']) : ''
				,'description' => $photo['description']
				,'original' => ($args['size'] == 'original') ? $this->flickr->buildPhotoURL($photo, 'original') : ''
			);
			
			$markup .= $this->RenderPhoto($settings);
		}
		
		$markup = $before_widget . $title . sprintf('<div style="text-align: center" id="wfm-recent-widget">%s</div>', $markup) . $after_widget;
		
		echo $markup;
	}
	
	function RenderRecentPhotoWidgetControl() 
	{
		$settings = $this->settings['recent_widget'];
		
		if(isset($_REQUEST['flickr-title'])) {
			$settings['title'] = $_REQUEST['flickr-title'];
			$settings['photos'] = (is_numeric($_REQUEST['flickr-photos'])) ? $_REQUEST['flickr-photos'] : 10;
			$settings['viewer'] = $_REQUEST['flickr-viewer'];
		}
		
		$this->SaveSetting('recent_widget', $settings);
		
		$options = array( ''		=> __('Disable', 'flickr-manager'),
						  'small'	=> __('Small', 'flickr-manager'), 
						  'medium'	=> __('Medium', 'flickr-manager'), 
						  'large'	=> __('Large', 'flickr-manager'));
		
		if($this->settings['is_pro'] == '1') 
			$options = array_merge($options, array('original' => __('Original', 'flickr-manager')));
		
		
		$markup = sprintf('<p><label for="flickr-title">%s:</label>
							<input id="flickr-title" class="widefat" type="text" value="%s" name="flickr-title" /></p>'
								, __('Title', 'flickr-manager')
								,htmlspecialchars($settings['title']));
		
		$markup .= sprintf('<p><label for="flickr-photos">%s:</label>
							<input id="flickr-photos" class="widefat" type="text" value="%s" name="flickr-photos" /></p>'
								, __('# Photos', 'flickr-manager')
								, htmlspecialchars($settings['photos']));
	
		$markup .= sprintf('<p><label for="flickr-viewer">%s:</label>
							<select name="flickr-viewer" class="widefat" id="flickr-viewer">'
								, __('Image Viewer', 'flickr-manager'));
		
		foreach ($options as $k => $v) {
			$markup .=  sprintf('<option value="%s" %s >%s</option>'
											,$k
											,($settings['viewer'] == $k) ? 'selected="selected"' : ''
											, htmlspecialchars($v));
		}
		
		$markup .= sprintf('</select><small>%s</small></p>'
								, __('This option will determine the image loaded into the Javascript viewer.', 'flickr-manager'));
								
		echo $markup;
	}
}

endif;



// Load plugin
global $flickr_manager, $flickr_panel, $flickr_admin, $flickr_overlay;
$flickr_manager = new FlickrManager();

// Load Modules
if(is_admin()) {
	//use oauth1, will callback and send token to here
	if(!(empty($_GET['oauth_token']) || empty($_GET['oauth_verifier'])) ){
		//get accesstoken
		//the same token?
		if($_SESSION['request_oauth_token']== $_GET['oauth_token']){	
			$flickr_oauth =  $flickr_manager->flickr->oauth_accesstoken( $_GET['oauth_token'],$_SESSION['request_oauth_token_secret'], $_GET['oauth_verifier']);
			//token and secret are here
			if(!empty($flickr_oauth['oauth_token'])){
				$flickr_manager->flickr->token_secret=$flickr_oauth['oauth_token_secret'];
				$flickr_manager->flickr->setToken($flickr_oauth['oauth_token'] );
				$flickr_manager->saveAccessToken($flickr_oauth);				
			}else {
				var_dump($flickr_oauth);
			}			
		}	
	}
		// Load Media Panel
	require_once (dirname ( __FILE__ ) . '/FlickrPanel.php');
	$flickr_panel = new FlickrPanel ();
	
	// Load Administration Pages
	require_once (dirname ( __FILE__ ) . '/FlickrAdmin.php');
	$flickr_admin = new FlickrAdmin ();
} else {
	// Load Image Viewer
	require_once(dirname(__FILE__) . '/OverlayLoader.php');
	$flickr_overlay = new OverlayLoader(WFM_OVERLAY_DIR);
}
?>