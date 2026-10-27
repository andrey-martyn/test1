<?php
class ControllerExtensionModuleUniTool extends Controller {
	private $uniset;
	
	public function index() {
		$uniset = $this->uniset = $this->config->get('config_unishop2');
		$version = isset($uniset['version']) ? $uniset['version'] : '1.7.1.0';
		$route = isset($this->request->get['route']) ? $this->request->get['route'] : '';
		$store_id = (int)$this->config->get('config_store_id');
		
		//$dir_template = 'catalog/view/theme/'.$this->config->get('theme_unishop2_directory').'/';
		$dir_template = 'catalog/view/theme/unishop2/';
		$dir_style = $dir_template.'stylesheet/';
		$dir_script = $dir_template.'js/';
		
		$generated_style = $dir_style.'generated.'.(int)$this->config->get('config_store_id').'.css';
		
		$this->setGeneratedStyle($generated_style);
		
		//user css
		$user_style = $dir_style.'generated-user-style.'.$store_id.'.css';
		
		if(isset($uniset['user_css']) && $uniset['user_css'] != '' && !file_exists($user_style)) {
			$style = html_entity_decode($uniset['user_css'], ENT_QUOTES, 'UTF-8');
			
			$style_file = fopen($user_style, 'w');
			fwrite($style_file, $style);
			fclose($style_file);
		}
		
		if(file_exists($user_style)) {
			$this->document->addStyle($user_style);
		}
		
		if(isset($uniset['custom_style']) && $uniset['custom_style'] != '' && file_exists($dir_style.$uniset['custom_style'])) {
			$this->document->addStyle($dir_style.$uniset['custom_style']);
		}
		
		$data['styles'] = $this->document->getStyles();
		
		$css_arr = $this->getMergedStyle($route, $dir_style, $generated_style);
		
		if($css_arr) {
			$data['merged_style'] = $css_arr['merged'].'?v='.$version;
			$data['styles'] = $css_arr['styles'];
		} else {
			$data['merged_style'] = '';
		}
		
		$js_arr = $this->getMergedScript($route, $dir_script);
		
		if($js_arr) {
			$data['merged_script'] = $js_arr['merged'];
			$data['scripts'] = $js_arr['scripts'];
		} else {
			$data['merged_script'] = '';
		}
		
		$data['version'] = $version;
		$data['generated_style'] = $generated_style.'?v='.(isset($uniset['save_date']) ? $uniset['save_date'] : strtotime('now'));
		
		return $data;
	}
	
	private function getMergedStyle($route, $path, $generated_style) {
		$uniset = $this->uniset;
		
		if(!isset($uniset['merge_css'])) {
			return false;
		}
		
		$stop_routes = [
			'extension/module/uni_pwa/fallbackPage',
			'checkout/simplecheckout',
			'checkout/uni_checkout',
			'checkout/checkout'
		];
		
		if(in_array($route, $stop_routes)) {
			return false;
		}
		
		$stop_styles = [
			//$path.'slideshow.css'
		];
		
		$uni_styles = [
			$path.'bootstrap.min.css',
			$path.'stylesheet.css',
			$path.'font-awesome.min.css',
			$path.'animate.css',
			$generated_style
		];
		
		$new_styles_arr = [];
		
		foreach($this->document->getStyles() as $style) {
			if ($stop_styles && in_array($style['href'], $stop_styles)) {
				$new_styles_arr[] = $style;
			} else {
				$uni_styles[] = $style['href'];
			}
		}
		
		$merged_file = $path.'merged.'.substr(md5(implode(',', $uni_styles)), 0, 8).'.min.css';
		
		if (!file_exists($merged_file)) {
			
			//$merged_dir = $path.'merged';
			
			//if(!is_dir($merged_dir)) {
				//mkdir($merged_dir, 0755, true);
			//}
			
			$contents = '';
		
			foreach($uni_styles as $filename) {
				if(strpos($filename, 'css?v')) {
					$filename = substr($filename, 0, strpos($filename, 'css?v')+3);
				}
				
				$filename = ltrim($filename, '/');
				
				if(file_exists($filename)) {
					$handle = fopen($filename, "r");
					$contents .= fread($handle, filesize($filename));
					fclose($handle);
				} else {
					$this->log->write('Warning: not found '.$filename);
				}
			}
			
			//stackoverflow.com/questions/15195750/minify-compress-css-with-regex
			//github.com/matthiasmullie/minify
		
			$contents = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $contents);
			$contents = preg_replace('/^\s*/m', '', $contents);
			$contents = preg_replace('/\s*$/m', '', $contents);
			$contents = preg_replace('/\s+/', ' ', $contents);
			$contents = preg_replace('/\s*([\*$~^|]?+=|[{};,>~]|!important\b)\s*/', '$1', $contents);
			$contents = preg_replace('/([\[(:>\+])\s+/', '$1', $contents);
			$contents = preg_replace('/\s+([\]\)>\+])/', '$1', $contents);
			$contents = preg_replace('/\s+(:)(?![^\}]*\{)/', '$1', $contents);
			
			$contents = trim($contents);
			
			file_put_contents($merged_file, $contents);
		}
		
		return [
			'merged' => $merged_file,
			'styles' => $new_styles_arr
		];
	}
	
	private function getMergedScript($route, $path) {
		$uniset = $this->uniset;
		
		if(!isset($uniset['merge_js'])) {
			return false;
		}
		
		$stop_routes = [
			'checkout/simplecheckout',
			'checkout/uni_checkout',
			'checkout/checkout'
		];
		
		if(in_array($route, $stop_routes)) {
			return false;
		}
		
		$stop_scripts = [
			//$path.'login-register.js'
		];
		
		$uni_scripts = [
			//'catalog/view/javascript/jquery/jquery-2.1.1.min.js',
			$path.'jquery-2.2.4.min.js',
			$path.'bootstrap.min.js',
			$path.'common.js',
			$path.'menu-aim.min.js',
			$path.'owl.carousel.min.js',
			$path.'jquery.highlight.min.js',
		];
		
		$new_scripts_arr = [];
		
		foreach($this->document->getScripts() as $script) {
			if ($stop_scripts && in_array($script, $stop_scripts)) {
				$new_scripts_arr[] = $script;
			} else {
				$uni_scripts[] = $script;
			}
		}

		$merged_file = $path.'merged.'.substr(md5(implode(',', $uni_scripts)), 0, 8).'.min.js';
		
		if (!file_exists($merged_file)) {
			
			$contents = '';
			
			$google_min = isset($uniset['merge_js_closure']) ? true : false;
			
			foreach($uni_scripts as $filename) {
				
				if(strpos($filename, 'js?v')) {
					$filename = substr($filename, 0, strpos($filename, 'js?v')+2);
				}
				
				$filename = ltrim($filename, '/');
				
				if(file_exists($filename)) {
					$handle = fopen($filename, "r");
					$data = fread($handle, filesize($filename));
					fclose($handle);
				
					if($google_min && substr($filename, -6) != 'min.js') {
						$output = $this->GoogleMin($data);
				
						if($output) {
							$data = $output;
						} else {
							$this->log->write('Warning: Google Closure Compiler not compile '.$filename);
						}
					}
				
					$contents .= $data;
				} else {
					$this->log->write('Warning: not found '.$filename);
				}
			}
			
			//github.com/matthiasmullie/minify
			
			$contents = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $contents);
			$contents = preg_replace('/^\/\/!.+(?:\r\n|\r|\n)/m', '', $contents);
			$contents = preg_replace('/[^\S\n]+/', ' ', $contents);
			$contents = str_replace(array(" \n", "\n "), "\n", $contents);
			$contents = preg_replace('/\n+/', "\n", $contents);
			$contents = preg_replace('/\breturn\s+(["\'\/\+\-])/', 'return$1', $contents);
			$contents = preg_replace('/\)\s+\{/', '){', $contents);
			$contents = preg_replace('/}\n(else|catch|finally)\b/', '}$1', $contents);
			
			$contents = trim($contents);
			
			file_put_contents($merged_file, $contents);
		}
		
		return [
			'merged'  => $merged_file,
			'scripts' => $new_scripts_arr
		];
	}
	
	private function GoogleMin($data) {
		$post_data = http_build_query(
			array(
				'compilation_level'	=> 'SIMPLE_OPTIMIZATIONS',
				'js_code' 			=> $data,
				'output_format' 	=> 'text',
				'output_info' 		=> 'compiled_code'
			),
			null,
			'&'
		);
			
		$curl = curl_init('https://closure-compiler.appspot.com/compile');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded'));
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		$result = curl_exec($curl);
		curl_close($curl);
		
		return ($result !== false && $result != '' && substr($result, 0, 5) != 'Error') ? $result : '';
	}
	
	private function setGeneratedStyle($generated_file) {
		$uniset = $this->uniset;
		
		if(file_exists($generated_file)) {
			return false;
		}
			
		$style = '';
		
		//if background image or background color
		if((isset($uniset['background_image']) && $uniset['background_image'] != '') || (isset($uniset['background_color']) && $uniset['background_color'] != 'fff' && $uniset['background_color'] != 'ffffff')) {
			
			if($uniset['background_image'] != '') {
				$style .= 'body {background-image:url("/image/'.$uniset['background_image'].'")}';
			}
			
			if($uniset['background_color'] != 'ffffff') {
				$style .= 'body {background-color:#'.$uniset['background_color'].'}';
			}
			
			if($uniset['menu_type'] == 1) {
				$style .= '@media (min-width:767px) {';
				$style .= 'header{margin:0 auto;padding:0 5px;background:#fff}';
				$style .= 'main{margin:0 auto;padding:20px 5px;background:#fff}';
				$style .= '.footer{margin:0 auto;padding-left:5px;padding-right:5px;opacity:.9}';
				$style .= '#subscribe{margin:-15px -5px 15px !important}';
				$style .= '}';
			} else {
				$style .= '@media (min-width:767px) {';
				$style .= 'header > .container, footer > .container, .top-menu > .container, .subscribe > .container, .footer__socials-payments .container {padding:0}';
				$style .= 'main{margin:20px auto 30px;background:#fff;border-radius:6px}';
				$style .= 'main > .container {padding:0px 20px}';
				$style .= 'footer{opacity:.9}';
				$style .= '.fly-menu > .container{padding:0 20px}';
				$style .= '}';
			}
			
			$style .= '@media (max-width:767px) {body {background:#fff}}';
		}
		
		if($uniset['menu_type'] == 2) {
			$style .= '@media (min-width:992px) {';
			$style .= '.menu2:before{position:absolute;left:50%;width:100vw;height:100%;content:"";background:inherit;transform:translateX(-50%)}';
			$style .= '}';
		}
		
		$style .= '.pwa-banner {color:#'.$uniset['pwa']['banner']['color'].'; background:#'.$uniset['pwa']['banner']['bg'].'}';
		$style .= '.pwa-banner__install {color:#'.$uniset['pwa']['banner']['color_btn'].'; background:#'.$uniset['pwa']['banner']['color_btn_bg'].'}';
		$style .= '.pwa-banner__close {color:#'.$uniset['pwa']['banner']['color_btn_bg'].'}';
		
		//basic  elements
		$style .= 'body {color:#'.$uniset['text_color'].'}';
		$style .= 'h1 {color:#'.$uniset['h1_color'].'}';
		$style .= 'h2 {color:#'.$uniset['h2_color'].'}';
		$style .= 'h3 {color:#'.$uniset['h3_color'].'}';
		$style .= 'h4 {color:#'.$uniset['h4_color'].'}';
		$style .= 'h5 {color:#'.$uniset['h5_color'].'}';
		$style .= 'div.heading {color:#'.$uniset['h3_heading_color'].'}';
		$style .= 'a {color:#'.$uniset['a_color'].'}';
		$style .= 'a:hover, a:focus, a:active {color:#'.$uniset['a_color_hover'].'}';
		$style .= '.rating i, .rating sup a {color:#'.$uniset['rating_star_color'].'}';
		//$style .= 'label.input input[type="radio"]:checked + span, label.input input[type="checkbox"]:checked + span {background:#'.$uniset['checkbox_radiobutton_bg'].'}';
		
		$style .= 'label.input input[type="radio"]:checked, label.input input[type="checkbox"]:checked{background:#'.$uniset['checkbox_radiobutton_bg'].'}';
		
		$style .= '.tooltip-inner {color:#'.$uniset['tooltip_color'].';background:#'.$uniset['tooltip_bg'].'}';
		$style .= '.tooltip.top .tooltip-arrow {border-top-color:#'.$uniset['tooltip_bg'].'}';
		$style .= '.tooltip.bottom .tooltip-arrow {border-bottom-color:#'.$uniset['tooltip_bg'].'}';
		$style .= '.tooltip.left .tooltip-arrow {border-left-color:#'.$uniset['tooltip_bg'].'}';
		$style .= '.tooltip.right .tooltip-arrow {border-right-color:#'.$uniset['tooltip_bg'].'}';
			
		$style .= '.form-control.input-warning{border-color:#'.$uniset['text_alert_color'].'}';
		$style .= '.text-danger{color:#'.$uniset['text_alert_color'].'}';
		
		$style .= isset($uniset['menu_type']) && $uniset['menu_type'] == 2 ? '.breadcrumb.col-md-offset-4.col-lg-offset-3{margin-left:0 !important}' : '';
				
		//top menu
		$style .= '.top-menu {background:#'.$uniset['top_menu_bg'].'}';
		$style .= '.top-menu__btn {color:#'.$uniset['top_menu_color'].'}';
		$style .= '.top-menu__btn:hover, #top .open .btn-group__btn {color:#'.$uniset['top_menu_color_hover'].'}';
		$style .= '@media (min-width:992px) {';
		$style .= '.top-links__a {color:#'.$uniset['top_menu_color'].'!important}';
		$style .= '.top-links__a:hover {color:#'.$uniset['top_menu_color_hover'].'!important}';
		$style .= '}';
			
		//search block
		$style .= '.header-search__category-btn {color:#'.$uniset['search_btn_color'].';background:#'.$uniset['search_btn_bg'].'}';
		$style .= '.header-search__btn {color:#'.$uniset['search_input_color'].'}';
		$style .= '.header-search__input{color:#'.$uniset['search_input_color'].'}';
		$style .= '.header-search__input::-webkit-input-placeholder{color:#'.$uniset['search_input_color'].'}';
		$style .= '.header-search__input::-moz-placeholder{color:#'.$uniset['search_input_color'].' }';
		$style .= '.header-search__input:-ms-input-placeholder{color:#'.$uniset['search_input_color'].'}';
		$style .= '.header-search__input:-input-placeholder{color:#'.$uniset['search_input_color'].'}';
			
		//phone block
		$style .= '.header-phones__main{color:#'.$uniset['main_phone_color'].'}';
		$style .= '.header-phones__additional{color:#'.$uniset['a_color'].'}';
		$style .= '.header-phones__additional.selected{color:#'.$uniset['a_color_hover'].'}';
		$style .= '.header-phones__a{color:#'.$uniset['additional_phone_color'].'!important}';
		$style .= '.header-phones__callback{color:#'.$uniset['a_color'].'!important}';
			
		//cart block
		$style .= '.header-cart__icon{color:#'.$uniset['cart_color'].'}';
		$style .= '.header-cart__total-items {color:#'.$uniset['cart_color_total'].';background:#'.$uniset['cart_bg_total'].'}';
			
		//main menu
		//if($uniset['menu_type'] == 1) {
		$style .= '.menu1 {color:#'.$uniset['main_menu_color'].';background:#'.$uniset['main_menu_bg'].'}';
		$style .= '.menu1 .menu__header{color:#'.$uniset['main_menu_color'].'}';
		$style .= '.menu1 .menu__collapse {background:#'.$uniset['main_menu_parent_bg'].'}';
		$style .= '.menu1 .menu__level-1-li:hover {background:#'.$uniset['main_menu_children_bg'].'}';
		$style .= '.menu1 .menu__level-1-a {color:#'.$uniset['main_menu_parent_color'].'}';
		$style .= '.menu1 .menu__level-1-a:hover {color:#'.$uniset['main_menu_parent_color_hover'].'}';
		$style .= '.menu1 .menu__level-1-pm {color:#'.$uniset['main_menu_parent_color'].'}';
		$style .= '.menu1 .menu__level-2 {background:#'.$uniset['main_menu_children_bg'].'}';
		$style .= '.menu1 .menu__level-2-a {color:#'.$uniset['main_menu_children_color'].'}';
		$style .= '.menu1 .menu__level-2-a:hover {color:#'.$uniset['main_menu_children_color_hover'].'}';
		$style .= '.menu1 .menu__level-2-pm {color:#'.$uniset['main_menu_children_color'].'}';
		$style .= '.menu1 .menu__level-3-a {color:#'.$uniset['main_menu_children_color2'].'}';
		$style .= '.menu1 .menu__level-3-a:hover {color:#'.$uniset['main_menu_children_color2_hover'].'}';
		//}
		
		$style .= '.menu1 .menu__more {color:#'.$uniset['main_menu_children_color'].'}';
		
		//main menu second level top position
		if(isset($uniset['main_menu_sec_lev_pos'])) {
			$style .= '@media (min-width:992px){';
			$style .= '.menu:not(.menu2) .menu__level-1-li {position:static}';
			$style .= '.menu:not(.menu2) .menu__level-1-li.has-children:hover {border-right:solid 1px transparent}';
			$style .= '.menu:not(.menu2) .menu__level-2 {top:0;min-height:100%;border-left:0;}';
			$style .= '}';
		}
		
		//main menu type2
		if($uniset['menu_type'] == 2) {
			//$style .= '@media (min-width:992px) {';
			$style .= '.menu2 {color:#'.$uniset['main_menu2_color'].';background:#'.$uniset['main_menu2_bg'].'}';
			$style .= '.menu2 .menu__level-1-a, .menu2 .menu__header {color:#'.$uniset['main_menu2_color'].'}';
			$style .= '.menu2 .menu__level-1-pm {color:#'.$uniset['main_menu2_color'].'}';
			$style .= '.menu2 .menu__level-2 {background:#'.$uniset['main_menu2_children_bg'].'}';
			$style .= '.menu2 .menu__level-2-a {color:#'.$uniset['main_menu2_children_color'].'}';
			$style .= '.menu2 .menu__level-2-a:hover {color:#'.$uniset['main_menu2_children_color_hover'].'}';
			$style .= '.menu2 .menu__level-2-pm {color:#'.$uniset['main_menu2_children_color'].'}';
			$style .= '.menu2 .menu__level-3-a {color:#'.$uniset['main_menu2_children_color2'].'}';
			$style .= '.menu2 .menu__level-3-a:hover {color:#'.$uniset['main_menu2_children_color2_hover'].'}';
			
			$style .= '.menu2 .menu__more {color:#'.$uniset['main_menu2_children_color'].'}';
			
			//$style .= '}';
			$style .= '/* menu for max 992 */';
			$style .= '@media (max-width:992px) {';
			$style .= '.menu2 .menu__collapse {color:#'.$uniset['main_menu2_color'].' !important;background:#'.$uniset['main_menu2_bg'].' !important}';
			$style .= '}';
		}
			
		//custom menu
		$style .= '#custom_menu .nav {background:#'.$uniset['main_menu_parent_bg'].'}';
		$style .= '#custom_menu .nav > li > a, #custom_menu .nav li > .visible-xs i {color:#'.$uniset['main_menu_parent_color'].'}';
		$style .= '#custom_menu .nav > li:hover > a, #custom_menu .nav > li:hover > .visible-xs i {color:#'.$uniset['main_menu_parent_color_hover'].'}';
		$style .= '#custom_menu .nav > li > .dropdown-menu {background:#'.$uniset['main_menu_children_bg'].'}';
		$style .= '#custom_menu .nav > li:hover {background:#'.$uniset['main_menu_children_bg'].'}';
		$style .= '#custom_menu .nav > li.has_chidren:hover:before {background:#'.$uniset['main_menu_children_bg'].'}';
		$style .= '#custom_menu .nav > li ul > li > a {color:#'.$uniset['main_menu_children_color'].'}';
		$style .= '#custom_menu .nav > li ul li ul > li > a {color:#'.$uniset['main_menu_children_color2'].'}';
			
		//other menu
		$style .= '.list-group a, .list-group a:focus {color:#'.$uniset['main_menu_parent_color'].';background:#'.$uniset['main_menu_parent_bg'].'}';
		$style .= '.list-group a.active, .list-group a.active:hover, .list-group a.active:focus, .list-group a:hover {color:#'.$uniset['main_menu_parent_color_hover'].';background:#'.$uniset['main_menu_children_bg'].'}';
			
		//right menu
		$style .= '.menu-right  {background:#'.$uniset['right_menu']['bg'].'}';
		$style .= '.menu-right .menu__level-1-a{color:#'.$uniset['right_menu']['col'].'}';
		$style .= '.menu-right .menu__level-1-a:hover {color:#'.$uniset['right_menu']['col_hov'].'}';
		$style .= '.menu-right .menu__level-2 {background:#'.$uniset['right_menu']['child_bg'].'}';
		$style .= '.menu-right .menu__level-2-a {color:#'.$uniset['right_menu']['child_col'].'}';
		$style .= '.menu-right .menu__level-2-a:hover {color:#'.$uniset['right_menu']['child_col_hov'].'}';
		$style .= '.menu-right .menu__level-3-a {color:#'.$uniset['right_menu']['child2_col'].'}';
		$style .= '.menu-right .menu__level-3-a:hover {color:#'.$uniset['right_menu']['child2_col_hov'].'}';
		
		//breadcrumb
		if(isset($uniset['breadcrumbs']['hide']['mobile'])) {
			$style .= '@media (max-width:768px) {';
			$style .= '.breadcrumb.mobile li:not(:first-child):not(:last-child) {display:none}';
			$style .= '}';
		}
		
		//buttons
		$style .= '.btn-default {color:#'.$uniset['btn_default_color'].';background:#'.$uniset['btn_default_bg'].'}';
		$style .= '.btn-default:hover, .btn.btn-default:focus {color:#'.$uniset['btn_default_color_hover'].';background:#'.$uniset['btn_default_bg_hover'].'}';
		$style .= '.btn-primary {color:#'.$uniset['btn_primary_color'].';background:#'.$uniset['btn_primary_bg'].'}';
		$style .= '.btn-primary:hover, .btn.btn-primary:focus {color:#'.$uniset['btn_primary_color_hover'].';background:#'.$uniset['btn_primary_bg_hover'].'}';
		$style .= '.btn-danger {color:#'.$uniset['btn_danger_color'].';background:#'.$uniset['btn_danger_bg'].'}';
		$style .= '.btn-danger:hover, .btn.btn-danger:focus {color:#'.$uniset['btn_danger_color_hover'].';background:#'.$uniset['btn_danger_bg_hover'].'}';
		
		//tabs
		$style .= '.nav-tabs > li > a {color:#'.$uniset['a_color'].'}';
		$style .= '.nav-tabs > li.active > a, .nav-tabs > li.active >a:focus, .nav-tabs > li.active > a:hover {color:#'.$uniset['a_color_hover'].'}';
		
		//$style .= '@media (max-width:767px) {';
			//if(!isset($uniset['tabs']['mobile']['old'])) {
				//$style .= '.nav-tabs{margin:0 0 10px;padding:0 0 7px;overflow:auto;white-space:nowrap}';
				//$style .= '.nav-tabs li{float:none;display:inline}';
				//$style .= '.nav-tabs li + li {margin:0 0 0 15px}';
				//$style .= '.nav-tabs li a{display:inline;margin:0;padding:0;font-size:1em;text-transform:uppercase;background:none !important;border:none !important}';
				//$style .= '.nav-tabs li a i{display:none}';
				//$style .= '.nav-tabs li.active a{border-bottom:solid 2px !important}';
				//$style .= '.nav-tabs li a:after{display:none}';
			//} else {
				//$style .= '.nav-tabs{margin:0 0 15px;border:solid 1px rgba(0, 0, 0, .1);border-radius:4px;overflow:hidden}';
				//$style .= '.nav-tabs li, .nav-tabs li.active{float:none;margin:0}';
				//$style .= '.nav-tabs li + li{border-top:solid 1px rgba(0, 0, 0, .1)}';
				//$style .= '.nav-tabs li a{margin:0;padding:8px 10px;border:none !important;border-radius:0}';
				//$style .= '.nav-tabs li a i{min-width:15px}';
				//$style .= '.nav-tabs li a:after{display:none}';
			//}
		//$style .= '}';
		
		if(isset($uniset['tabs']['mobile']['old'])) {
			$style .= '.nav-tabs{margin-bottom:15px}';
			$style .= '.nav-tabs li a{margin:0 5px 0 0;padding:10px 10px 10px 15px;font-size:.95em;font-weight:500;background:#f5f5f5;border:solid 1px #ddd}';
			$style .= '.nav-tabs li a i{margin:0 10px 0 0}';
			$style .= '.nav-tabs li a:after{background:#f5f5f5;content:"";position:absolute;z-index:1;top:-1px;right:-8px;bottom:-1px;width:15px;-webkit-transform:skew(20deg);-moz-transform:skew(20deg);-ms-transform:skew(20deg);-o-transform:skew(20deg);transform:skew(20deg);border-radius:0 5px 0 0;border-right:1px solid #ccc;border-top:1px solid #ddd;border-bottom:solid 1px #ddd}';
			$style .= '.nav-tabs li:hover a{border:solid 1px #ddd}';
			$style .= '.nav-tabs li:hover a:after{background:#eee}';
			$style .= '.nav-tabs li.active{z-index:2}';
			$style .= '.nav-tabs li.active a:after{background:#fff;border-bottom:none}';
			
			$style .= '@media (max-width:767px) {';
			$style .= '.nav-tabs{margin:0 0 15px;border:solid 1px rgba(0, 0, 0, .1);border-radius:4px;overflow:hidden}';
			$style .= '.nav-tabs li, .nav-tabs li.active{float:none;margin:0}';
			$style .= '.nav-tabs li + li{border-top:solid 1px rgba(0, 0, 0, .1)}';
			$style .= '.nav-tabs li a{margin:0;padding:8px 10px;border:none !important;border-radius:0}';
			$style .= '.nav-tabs li a i{min-width:15px}';
			$style .= '.nav-tabs li a:after{display:none}';
			$style .= '}';
		} else {
			$style .= '.nav-tabs{overflow-x:auto;margin:0 0 15px;padding:0 0 5px;white-space:nowrap;border-bottom:solid 1px rgba(0, 0, 0, .1)}';
			$style .= '.nav-tabs li{float:none;display:inline;margin:0}';
			$style .= '.nav-tabs li + li {margin:0 0 0 20px}';
			$style .= '.nav-tabs li a{display:inline;margin:0;padding:0;font-size:1.15em;font-weight:500;background:none !important;border:none !important}';
			$style .= '.nav-tabs li a:after{position:absolute;left:0;right:0;bottom:-7px;content:\'\';width:0;transition:all ease-in-out .15s;border-bottom:solid 2px !important}';
			$style .= '.nav-tabs li.active a:after, .nav-tabs li a:hover:after{width:100%}';
			$style .= '.nav-tabs i {position:relative;top:-1px;margin:0 7px 0 0;font-size:.8em}';
			$style .= '@media (min-width:992px) {.nav-tabs li a{font-size:1.2em}}';
		}
		
		//special timer
		$style .= '.uni-timer {background:#'.$uniset['special_timer_bg'].'}';
		$style .= '.uni-timer__text {color:#'.$uniset['special_timer_text_color'].'}';
		$style .= '.uni-timer__digit, .uni-timer__colon, .product-page__timer-heading {color:#'.$uniset['special_timer_color'].'}';
		
		//stock indicator
		if(isset($uniset['show_stock_indicator']) && $uniset['show_stock_indicator'] > 0) {
			if($uniset['show_stock_indicator'] == 1) {
				$style .= '.qty-indicator__percent.percent-5 {background:#'.$uniset['stock_i_c_5'].'}';
				$style .= '.qty-indicator__percent.percent-4 {background:#'.$uniset['stock_i_c_4'].'}';
				$style .= '.qty-indicator__percent.percent-3 {background:#'.$uniset['stock_i_c_3'].'}';
				$style .= '.qty-indicator__percent.percent-2 {background:#'.$uniset['stock_i_c_2'].'}';
				$style .= '.qty-indicator__percent.percent-1 {background:#'.$uniset['stock_i_c_1'].'}';
				$style .= '.qty-indicator__bar, .qty-indicator__percent.percent-0 {background:#'.$uniset['stock_i_c_0'].'}';
			} else {
				$style .= '.qty-indicator__text.text-5 {color:#'.$uniset['stock_i_c_5'].'}';
				$style .= '.qty-indicator__text.text-4 {color:#'.$uniset['stock_i_c_4'].'}';
				$style .= '.qty-indicator__text.text-3 {color:#'.$uniset['stock_i_c_3'].'}';
				$style .= '.qty-indicator__text.text-2 {color:#'.$uniset['stock_i_c_2'].'}';
				$style .= '.qty-indicator__text.text-1, .qty-indicator__text.text-0 {color:#'.$uniset['stock_i_c_1'].'}';
			}
		}
		
		//fly menu
		$style .= '.fly-menu .menu__header{background:#'.$uniset['fly_menu']['bg'].'}';
		$style .= '.fly-menu .menu__header, .fly-menu__phone {color:#'.$uniset['fly_menu']['color'].'}';
		$style .= '.fly-menu__cart-total-items {color:#'.$uniset['cart_color_total'].';background:#'.$uniset['cart_bg_total'].'}';
		$style .= '.fly-menu__icon {color:#'.$uniset['fly_menu']['color'].';background:#'.$uniset['fly_menu']['bg'].'}';
		
		if($uniset['menu_type'] == 2) {
			//$style .= '@media (min-width:992px) {';
			$style .= '#fly-menu .menu__header, #fly-menu .menu__collapse {color:#'.$uniset['main_menu2_color'].' !important;background:#'.$uniset['main_menu2_bg'].' !important}';
			$style .= '#fly-menu .menu__level-1-li:hover{background:rgba(0, 0, 0, 0.06) !important}';
			$style .= '#fly-menu .menu__level-1-a, #fly-menu .menu__level-1-pm  {color:#'.$uniset['main_menu2_color'].'}';
			$style .= '#fly-menu .menu__level-2-a {color:#'.$uniset['main_menu2_children_color'].'}';
			$style .= '#fly-menu .menu__level-2-a:hover {color:#'.$uniset['main_menu2_children_color_hover'].'}';
			$style .= '#fly-menu .menu__level-3-a {color:#'.$uniset['main_menu2_children_color2'].'}';
			$style .= '#fly-menu .menu__level-3-a:hover {color:#'.$uniset['main_menu2_children_color2_hover'].'}';
			$style .= '#fly-menu .menu__more {color:#'.$uniset['main_menu2_children_color'].'}';
			//$style .= '}';
		}
			
		//slideshow
		$style .= '.swiper-viewport .title{color:#'.$uniset['slideshow_title_color'].';background:#'.$uniset['slideshow_title_bg'].'}';
		$style .= '.swiper-viewport .swiper-pager .swiper-button-next:before, .swiper-viewport .swiper-pager .swiper-button-prev:before {color:#'.$uniset['slideshow_pagination_bg_active'].' !important}';
		$style .= isset($uniset['hide_slideshow_title']) ? '.swiper-viewport .title{display:none}' : '';
		$style .= '.swiper-viewport .swiper-pagination .swiper-pagination-bullet{background:#'.$uniset['slideshow_pagination_bg'].' !important}';
		$style .= '.swiper-viewport .swiper-pagination .swiper-pagination-bullet-active{background:#'.$uniset['slideshow_pagination_bg_active'].' !important}';
		
		//carousel
		$style .= '.owl-carousel .owl-dots .owl-dot span {background:#'.$uniset['carousel_pagination_bg'].'}';
		$style .= '.owl-carousel .owl-dots .owl-dot.active span {background:#'.$uniset['carousel_pagination_bg_active'].'}';
		$style .= '.owl-carousel .owl-nav button {color:#'.$uniset['slideshow_pagination_bg_active'].'}';
		
		//unislideshow
		$style .= '.uni-slideshow__title{color:#'.$uniset['unislideshow_title_color'].'}';
		$style .= '.uni-slideshow__text{color:#'.$uniset['unislideshow_text_color'].'}';
		$style .= '.uni-slideshow__btn, .uni-slideshow__btn:hover, .uni-slideshow__btn:focus{color:#'.$uniset['unislideshow_button_color'].';background:#'.$uniset['unislideshow_button_bg'].'}';
		$style .= '.uni-slideshow .owl-nav button {color:#'.$uniset['unislideshow_nav_bg_active'].'}';
		$style .= '.uni-slideshow .owl-dots .owl-dot span{background:#'.$uniset['unislideshow_nav_bg'].'}';
		$style .= '.uni-slideshow .owl-dots .owl-dot.active span{background:#'.$uniset['unislideshow_nav_bg_active'].'}';

		//banners
		$style .= '.uni-banner__item:hover .btn-primary{color:#'.$uniset['btn_primary_color_hover'].';background:#'.$uniset['btn_primary_bg_hover'].'}';
			
		//home text banners
		$style .= '.home-banner__item {background:#'.$uniset['home_banners_bg'].'}';
		$style .= '.home-banner__icon {color:#'.$uniset['home_banners_icon_color'].'}';
		$style .= '.home-banner__text {color:#'.$uniset['home_banners_text_color'].'}';
		
		//cat description
		$style .= ($uniset['cat_desc_pos'] == 'bottom') ? '.category-page.category-info{display:none}' : '';
			
		//product-thumb
		$style .= '.product-thumb__name{color:#'.$uniset['product_thumb_h4_color'].'}';
		$style .= '.product-thumb__name:hover{color:#'.$uniset['product_thumb_h4_color_hover'].'}';
		$style .= '.product-thumb__attribute-value{color:#'.$uniset['text_color'].'}';
		$style .= '.product-thumb__addit-dot.active{background:#'.$uniset['a_color'].'}';
		
		$style .= '@media (min-width:992px) {';
		
		if(isset($uniset['catalog']['description_hover'])) {
			$style .= 'body:not(.touch-support) .product-thumb .description {display:none}';
			$style .= 'body:not(.touch-support) .product-thumb .attribute {display:block}';
			$style .= '.product-thumb.hover .description{height:100% !important}';
		}
		
		if(isset($uniset['catalog']['attr_hover'])) {
			$style .= 'body:not(.touch-support) .product-thumb .attribute {display:none}';
			$style .= '.product-thumb.hover .attribute{height:100% !important}';
		}
		
		if(isset($uniset['catalog']['option_hover'])) {
			$style .= 'body:not(.touch-support) .product-thumb .option {display:none}';
			$style .= '.product-thumb.hover .option{height:100% !important}';
		}
		
		$style .= '}';
		
		if(isset($uniset['catalog']['items_on_mobile']) && $uniset['catalog']['items_on_mobile'] == 2) {
			$style .= '@media (max-width:360px) {.product-thumb__add-to-cart span {display:none}}';
			$style .= '@media (max-width:520px) {';
			$style .= '.uni-module {padding:0 5px}';
			$style .= '.uni-module .owl-item, .uni-module__item{padding:0 5px}';
			$style .= '.uni-module__item > div{margin:0 0 10px}';
			$style .= '.uni-module__item:last-of-type > div{margin:0 0 20px}';
			$style .= '.grid-view {float:left;width:50%}';
			$style .= '.grid-view .product-thumb {margin:0 0 10px}';
			$style .= '.grid-view:nth-child(odd) {padding-right:5px}';
			$style .= '.grid-view:nth-child(even) {padding-left:5px}';
			$style .= '.grid-view:last-of-type {margin-bottom:10px}';
			$style .= '.product-thumb .sticker {left:-11px}';
			$style .= '.product-thumb .sticker__item:before{display:none}';
			$style .= '.product-thumb__name {line-height:normal;font-weight:400}';
			$style .= '.product-thumb__price {font-size:1em}';
			$style .= '.product-thumb__price .price-old {margin-top:0}';
			$style .= '.product-thumb__add-to-cart {height:24px;font-size:.85em}';
			$style .= '.product-thumb__quick-order {height:24px;font-size:.85em;opacity:1}';
			$style .= '.product-thumb .uni-timer, .product-thumb__description, .product-thumb__option, .product-thumb .qty-switch, .product-thumb .qty-indicator{display:none!important}';
			$style .= '}';
		}
		
		//product
		$style .= '.product-page__image-addit-img.selected {border-color:#'.$uniset['a_color'].'}';
		$style .= '.quick-order__image-additional-img.selected {border-color:#'.$uniset['a_color'].'}';
			
		$style .= '.option select{color:#'.$uniset['options_color'].'}';
		$style .= '.option__name{color:#'.$uniset['options_color'].';background:#'.$uniset['options_bg'].'}';
		$style .= '.option__name:hover{border:solid 1px #'.$uniset['options_bg_active'].' !important}';
		$style .= '.option input:checked + .option__name{color:#'.$uniset['options_color_active'].';background:#'.$uniset['options_bg_active'].'}';
		$style .= '.option__img:hover, .option input:checked + .option__img{border-color:#'.$uniset['options_bg_active'].'}';
		$style .= '.option__popup.module{width:'.$uniset['options_popup_img_width'].'px}';
		$style .= '.option__popup.product{width:'.$uniset['options_popup_img_width_p'].'px}';
		$style .= '.option__popup.quick-order{width:'.$uniset['options_popup_img_width_q'].'px}';
			
		//price
		$style .= '.price {color:#'.$uniset['price_color'].'}';
		$style .= '.price .price-old {color:#'.$uniset['price_color_old'].'}';
		$style .= '.price .price-new {color:#'.$uniset['price_color_new'].'}';
			
		//cart btn
		$style .= '.add_to_cart {color:#'.$uniset['cart_btn_color'].';background:#'.$uniset['cart_btn_bg'].'}';
		$style .= '.add_to_cart:hover, .add_to_cart:focus, .add_to_cart:active {color:#'.$uniset['cart_btn_color_hover'].';background:#'.$uniset['cart_btn_bg_hover'].'}';
		$style .= '.add_to_cart.in_cart, .add_to_cart.in_cart:hover, .add_to_cart.in_cart:focus, .add_to_cart.in_cart:active{color:#'.$uniset['cart_btn_color_incart'].';background:#'.$uniset['cart_btn_bg_incart'].'}';
		$style .= '.add_to_cart.qty-0, .add_to_cart.qty-0:hover, .add_to_cart.qty-0:focus, .add_to_cart.qty-0:active{color:#'.$uniset['cart_btn_color_disabled'].';background:#'.$uniset['cart_btn_bg_disabled'].'}';	
		$style .= '.add_to_cart.disabled, .add_to_cart.disabled:hover, .add_to_cart.disabled:focus, .add_to_cart.disabled:active{color:#'.$uniset['cart_btn_color_disabled'].';background:#'.$uniset['cart_btn_bg_disabled'].'}';				
			
		//quick order btn
		$style .= '.btn.quick-order {color:#'.$uniset['quick_order_btn_color'].';background:#'.$uniset['quick_order_btn_bg'].'}';
		$style .= '.btn.quick-order:hover, .btn.quick-order:focus, .btn.quick-order:active {color:#'.$uniset['quick_order_btn_color_hover'].';background:#'.$uniset['quick_order_btn_bg_hover'].'}';
		$style .= isset($uniset['show_quick_order_always']) ? '.product-thumb__quick-order {opacity:1}' : '';
			
		//wishlist&compare btn
		$style .= '.wishlist, .wishlist a {color:#'.$uniset['wishlist_btn_color'].'}';
		$style .= '.wishlist:hover, .wishlist a:hover, .wishlist.active{color:#'.$uniset['wishlist_btn_color_hover'].'}';
		$style .= '.compare, .compare a {color:#'.$uniset['compare_btn_color'].'}';
		$style .= '.compare:hover, .compare a:hover, .compare.active{color:#'.$uniset['compare_btn_color_hover'].'}';
			
		//stickers
		$style .= '.sticker__item.reward {color:#'.$uniset['sticker_reward_text_color'].';background:#'.$uniset['sticker_reward_background_color'].'}';
		$style .= '.sticker__item.reward:before {border-top-color:#'.$uniset['sticker_reward_background_color'].';border-right-color:#'.$uniset['sticker_reward_background_color'].'}';
		$style .= '.sticker__item.special {color:#'.$uniset['sticker_special_text_color'].';background:#'.$uniset['sticker_special_background_color'].'}';
		$style .= '.sticker__item.special:before {border-top-color:#'.$uniset['sticker_special_background_color'].';border-right-color:#'.$uniset['sticker_special_background_color'].'}';
		$style .= '.sticker__item.bestseller {color:#'.$uniset['sticker_bestseller_text_color'].';background:#'.$uniset['sticker_bestseller_background_color'].'}';
		$style .= '.sticker__item.bestseller:before {border-top-color:#'.$uniset['sticker_bestseller_background_color'].';border-right-color:#'.$uniset['sticker_bestseller_background_color'].'}';
		$style .= '.sticker__item.new {color:#'.$uniset['sticker_new_text_color'].';background:#'.$uniset['sticker_new_background_color'].'}';
		$style .= '.sticker__item.new:before {border-top-color:#'.$uniset['sticker_new_background_color'].';border-right-color:#'.$uniset['sticker_new_background_color'].'}';
		$style .= '.sticker__item.sku {color:#'.$uniset['sticker_sku_text_color'].';background:#'.$uniset['sticker_sku_background_color'].'}';
		$style .= '.sticker__item.sku:before {border-top-color:#'.$uniset['sticker_sku_background_color'].';border-right-color:#'.$uniset['sticker_sku_background_color'].'}';
		$style .= '.sticker__item.upc {color:#'.$uniset['sticker_upc_text_color'].';background:#'.$uniset['sticker_upc_background_color'].'}';
		$style .= '.sticker__item.upc:before {border-top-color:#'.$uniset['sticker_upc_background_color'].';border-right-color:#'.$uniset['sticker_upc_background_color'].'}';
		$style .= '.sticker__item.ean {color:#'.$uniset['sticker_ean_text_color'].';background:#'.$uniset['sticker_ean_background_color'].'}';
		$style .= '.sticker__item.ean:before {border-top-color:#'.$uniset['sticker_ean_background_color'].';border-right-color:#'.$uniset['sticker_ean_background_color'].'}';
		$style .= '.sticker__item.jan {color:#'.$uniset['sticker_jan_text_color'].';background:#'.$uniset['sticker_jan_background_color'].'}';
		$style .= '.sticker__item.jan:before {border-top-color:#'.$uniset['sticker_jan_background_color'].';border-right-color:#'.$uniset['sticker_jan_background_color'].'}';
		$style .= '.sticker__item.isbn {color:#'.$uniset['sticker_isbn_text_color'].';background:#'.$uniset['sticker_isbn_background_color'].'}';
		$style .= '.sticker__item.isbn:before {border-top-color:#'.$uniset['sticker_isbn_background_color'].';border-right-color:#'.$uniset['sticker_isbn_background_color'].'}';
		$style .= '.sticker__item.mpn {color:#'.$uniset['sticker_mpn_text_color'].';background:#'.$uniset['sticker_mpn_background_color'].'}';
		$style .= '.sticker__item.mpn:before {border-top-color:#'.$uniset['sticker_mpn_background_color'].';border-right-color:#'.$uniset['sticker_mpn_background_color'].'}';
			
		//product text banners
		$style .= '.product-banner__item {background:#'.$uniset['product_banners_bg'].'}';
		$style .= '.product-banner__icon {color:#'.$uniset['product_banners_icon_color'].'}';
		$style .= '.product-banner__text {color:#'.$uniset['product_banners_text_color'].'}';
		
		//pagination
		$style .= '.pagination li a, .pagination li a:hover, .pagination li a:visited{color:#'.$uniset['pagination_color'].';background:#'.$uniset['pagination_bg'].'}';
		$style .= '.pagination li.active span, .pagination li.active span:hover, .pagination li.active span:focus{color:#'.$uniset['pagination_color_active'].';background:#'.$uniset['pagination_bg_active'].'}';
			
		//footer
		$style .= 'footer{background:#'.$uniset['footer_bg'].'}';
		$style .= 'footer h5{color:#'.$uniset['footer_h5_color'].'}';
		$style .= 'footer, footer a, footer a:hover, footer a:visited{color:#'.$uniset['footer_text_color'].'}';
		
		//subscribe
		$style .= '.subscribe__info {color:#'.$uniset['subscribe_text_color'].'}';
		$style .= '.subscribe__info div {color:#'.$uniset['subscribe_points_color'].'}';
		$style .= '.subscribe__input {color:#'.$uniset['subscribe_input_color'].';background:#'.$uniset['subscribe_input_bg'].'}';
		$style .= '.subscribe__input::-webkit-input-placeholder{color:#'.$uniset['subscribe_input_color'].'}';
		$style .= '.subscribe__input::-moz-placeholder{color:#'.$uniset['subscribe_input_color'].' }';
		$style .= '.subscribe__input:-ms-input-placeholder{color:#'.$uniset['subscribe_input_color'].'}';
		$style .= '.subscribe__input:-input-placeholder{color:#'.$uniset['subscribe_input_color'].'}';
		$style .= '.subscribe__btn, .subscribe__btn:hover {color:#'.$uniset['subscribe_button_color'].';background:#'.$uniset['subscribe_button_bg'].'}';
		
		//catwall
		$style .= '.category-wall__more{color:#'.$uniset['a_color_hover'].'}';
		
		//fly wishlist & compare
		$style .= '.fly-block__wishlist, .fly-block__wishlist-total {color:#'.$uniset['fly_wishlist_color'].';background:#'.$uniset['fly_wishlist_bg'].'}';
		$style .= '.fly-block__compare, .fly-block__compare-total {color:#'.$uniset['fly_compare_color'].';background:#'.$uniset['fly_compare_bg'].'}';
		
		//fly callback button
		$style .= '.fly-block__callback {color:#'.$uniset['fly_callback_color'].';background:#'.$uniset['fly_callback_bg'].'}';
		$style .= '.fly-block__callback:before, .fly-block__callback:after {border:solid 1px;border-color:#'.$uniset['fly_callback_bg'].' transparent}';
		$style .= isset($uniset['hide_fly_callback']) ? '@media (max-width:767px){.fly-block__callback {display:none}}' : '';
		
		//notification window
		$notification = isset($uniset['notification']) ? $uniset['notification'] : [];
		if($notification) {
			$style .= '.notification .modal-body {background:#'.$notification['bg'].'}';
			$style .= '.notification.fixed:before {background:#'.$notification['bg'].';opacity:.8}';
			$style .= '.notification__text {color:#'.$notification['color'].'}';
			$style .= '.notification__button.cancel {color:#'.$notification['color'].'}';
		}
		
		//manufacturer module
		if($uniset['menu_type'] == 1) {
			$style .= '#manufacturer_module .heading, #manufacturer_module .heading:after {color:#'.$uniset['main_menu_color'].' !important;background:#'.$uniset['main_menu_bg'].' !important}';
		} else {
			$style .= '#manufacturer_module .heading, #manufacturer_module .heading:after {color:#'.$uniset['main_menu2_color'].' !important;background:#'.$uniset['main_menu2_bg'].' !important}';
		}
		
		//ocfilter
		if($this->config->get('module_ocfilter_status')) {
			$style .= '.noUi-horizontal .noUi-handle {background:#'.$uniset['checkbox_radiobutton_bg'].'}';
		}
		
		//alerts
		$style .= '.alert-success{color:#'.$uniset['alert']['success']['color'].';background:#'.$uniset['alert']['success']['bg'].'}';
		$style .= '.alert-success a{color:#'.$uniset['alert']['success']['color'].'}';
		$style .= '.alert-warning{color:#'.$uniset['alert']['warning']['color'].';background:#'.$uniset['alert']['warning']['bg'].'}';
		$style .= '.alert-warning a{color:#'.$uniset['alert']['warning']['color'].'}';
		$style .= '.alert-danger{color:#'.$uniset['alert']['danger']['color'].';background:#'.$uniset['alert']['danger']['bg'].'}';
		$style .= '.alert-danger a{color:#'.$uniset['alert']['danger']['color'].'}';
		
		//blur on hover menu
		if($uniset['main_menu_blur'] == 1) {
			$style .= '.blur > *{filter:blur(2px);-webkit-filter:blur(2px)}';
			$style .= '#top:after {display:block;position:fixed;z-index:99;top:0;bottom:0;left:0;width:100%;content:"";background:#fff;visibility:hidden;opacity:0;transition:opacity linear .1s}';
			$style .= '#top.blur:after{visibility:visible;opacity:.5}';
		} elseif($uniset['main_menu_blur'] == 2) {
			$style .= '.blur > *{filter:blur(2px);-webkit-filter:blur(2px)}';
			$style .= '#top:after {display:block;position:fixed;z-index:99;top:0;bottom:0;left:0;width:100%;content:"";background:#000;visibility:hidden;opacity:0;transition:opacity linear .1s}';
			$style .= '#top.blur:after{visibility:visible;opacity:.5}';
		}
		
		//blur on popup show
		if(isset($uniset['popup_blur'])) {
			$style .= 'body.modal-open header, body.modal-open main, body.modal-open footer{filter:blur(2px)}';
		}
		
		//blur on popup img show
		if(isset($uniset['popup_img_blur'])) {
			$style .= 'body.magnific-open > *:not(.mfp-wrap){filter:blur(2px);transition:.1s}';
		}
			
		file_put_contents($generated_file, $style);
	}
}
?>