<?php
/*
Plugin Name: Ekklesia 360 Importer
Description: Import pages and articles from an Ekklesia 360 site. Automatically imports images and other attachments to the Wordpress media library. The import of sermons is also possible of Message Manager is install.
Version: 1.0.0
Author: Chris Roemmich
Author URI: https://cr-wd.com
License: MIT
*/

class Ekklesia_Importer {
	
	/** the ekklesia version */
	static $version = '1.0.0';
	
	/** prefix for meta and option values */
	static $prefix = 'ekklesia_importer_';
	
	/** the path of the plugin directory */
	static $path;
	
	/** the url of the plugin directory */
	static $url;
	
	/** called when the object is created */
	function __construct() {
		$this->init_paths();
				
		add_action('admin_menu', array($this, '_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, '_admin_enqueue_scripts'));
		
		add_action('wp_ajax_ekklesia_importer_control', array($this, '_ajax_control'));
		add_action('wp_ajax_ekklesia_importer_loop', array($this, '_ajax_loop'));
	}
	
	/** attempts to determine the correct paths when symlinked */
	function init_paths() {
		if (defined("ABSPATH")) {
			// check if the file systems match, if not, the plugin is likely symlinked
			if (strpos(plugin_dir_path( __FILE__ ), ABSPATH) !== 0) {
				// assume the plugin is in the default spot
				Ekklesia_Importer::$path = ABSPATH . 'wp-content/plugins/ekklesia-importer/';
				Ekklesia_Importer::$url = site_url('/') . 'wp-content/plugins/ekklesia-importer/';
				return;
			}
		}
		// go with the "safe" values
		Ekklesia_Importer::$path = plugin_dir_path( __FILE__ );
		Ekklesia_Importer::$url = plugin_dir_url( __FILE__ );
	}
	
	/** the admin_menu callback */
	function _admin_menu() {
		add_submenu_page('tools.php', "Ekklesia 360 Importer", "Ekklesia 360 Importer", 'import', 'ekklesia-importer', array($this, '_import_page'));
	} // end _admin_menu
	
	/** the admin_enqueue_scripts callback */
	function _admin_enqueue_scripts($hook) {
		if (strripos($hook, 'ekklesia-importer') !== false) {
			wp_enqueue_style('ekklesia-importer-jquery-ui-css', Ekklesia_Importer::$url.'includes/jquery-ui/jquery-ui-1.8.22.custom.css', array(), '1.8.22');
			wp_enqueue_style('ekklesia-importer-styles', Ekklesia_Importer::$url.'css/styles.css', array(), Ekklesia_Importer::$version);
			wp_enqueue_script('ekklesia-importer-jquery-ui-js', Ekklesia_Importer::$url.'includes/jquery-ui/jquery-ui-1.8.22.custom.min.js', array('jquery'), '1.8.22');
		}
	} // end _admin_enqueue_scripts
	
	/** builds the import page */
	function _import_page() {
		if (!current_user_can('import')) wp_die(__('You do not have sufficient permissions to access this page.'));
		
		if (isset($REQUEST['reset'])) {
			$this->reset_import();
		}
		
		function is_selected_class($name, $page) {
			if ($name == $page) echo ' class="selected"';
		}
		
		function display_indicator($page) { ?>
			<div class="ekklesia_importer_page_indicator">
				<ul>
					<li<?php is_selected_class('welcome', $page); ?>>Welcome > </li>
					<li<?php is_selected_class('options', $page); ?>>Options > </li>
					<li<?php is_selected_class('import', $page); ?>>Import</li>
				</ul>
			</div><?php
		} ?>
		
		<div class="wrap">
			<?php screen_icon(); ?>	
			<h2>Ekklesia 360 Importer</h2>
			
			<div class="ekklesia_importer_no_js error">Javascript is required to import content from Ekklesia. Please enable in your brower settings an reload this page.</div>
						
			<div id="ekklesia_importer_ajax">
			
				<div id="ekklesia_importer_error" class="error"></div>
				<div id="ekklesia_importer_notice" class="updated"></div>
				<div id="ekklesia_importer_success" class="updated"></div>
			
				<div id="ekklesia_importer_lock" class="error">
					<h3>Import Locked</h3>
					<p>Import can only be run by one user at a time in a single window. A lock is created when you begin the import wizzard. If you are sure no other instances are running, click <a href="#" id="ekklesia_importer_unlock">here</a>.</p>				
				</div>
				
				<div id="ekklesia_importer_welcome">
					<?php display_indicator('welcome'); ?>
				
					<h3>Let's get started!</h3>
					<p>The Ekklesia 360 Importer will help you import pages and articles from your Ekklesia site. If you have Message Manager installed, you may be able to import sermons as well.</p>
					<p>Before you can can begin importing. You must <strong>upload</strong> the <strong>wordpress-export.php</strong> file contained in the contrib folder of this plugin <strong>to the root of your Ekklesia web directory</strong>. Once you have done this, click "Get Started!".</p>
				</div> <!-- end #ekklesia_importer_welcome -->
				
				<div id="ekklesia_importer_options">
					<?php display_indicator('options'); ?>
				
					<h3>Import Options</h3>
				
					<h4>General Options</h4>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="url">Export Url:</label></th>
							<td><input type="text" name="url" value="<?php echo $this->get_import_option('url'); ?>" class="regular-text" />
								<p class="description">Enter the url of the wordpress-export.php file on your Ekklesia 360 site. It will be something like: http://www.ekk360site.com/wordpress-export.php</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="key">Shared Key:</label></th>
							<td><input type="text" name="key" value="<?php echo $this->get_import_option('key'); ?>" class="regular-text" />
								<p class="description">Enter the shared key you set in wordpress-export.php.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="what[]">Import Items:</label></th>
							<td>
								<ul>
									<?php 
										function is_checked($value, $checked) {
											if (array_search($value, $checked)) {
												echo 'checked="checked"';
											}
										}
										$checked = $this->get_import_option('what', array());
										if (!is_array($checked)) {
											$checked = array($checked);	
										}
									?>
									<li><input type="checkbox" name="what[]" value="pages" <?php is_checked('pages', $checked); ?> /> Pages</li>
									<li><input type="checkbox" name="what[]" value="articles" <?php is_checked('articles', $checked); ?> /> Articles</li>
									<?php if(class_exists('Message_Manager')): ?>
									<li><input id="ekklesia_importer_import_messages" type="checkbox" name="what[]" value="messages" <?php is_checked('messages', $checked); ?> /> Sermons</li>
									<?php else: ?>
									<li><input type="checkbox" name="what[]" value="messages" disabled="disabled" /> Sermons (Message Manager plugin required)</li>
									<?php endif; ?>
								</ul>
								<p class="description">Check all of the items you would like to import.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="download_media">Download Media:</label></th>
							<td>
								<?php
									$selected = $this->get_import_option('download_media', 'yes');
									
								?>
								<select name="download_media">
								  <option value="yes" <?php selected($selected, 'yes'); ?> >Yes, import content.</option>
								  <option value="no" <?php selected($selected, 'no'); ?> >No.</option>
								</select>
								<p class="description">Would you like to download images and other attachments into your Wordpress library? Choose yes unless you know the ramifications of remotely linked content.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="responsive_images">Make Images Responsive:</label></th>
							<td>
								<?php
									$selected = $this->get_import_option('responsive_images', 'no');
								?>
								<select name="responsive_images">
								  <option value="yes" <?php selected($selected, 'yes'); ?> >Yes, make images reponsive.</option>
								  <option value="no" <?php selected($selected, 'no'); ?> >No.</option>
								</select>
								<p class="description">Remove height and width on images to make them responsive?</p>
							</td>
						</tr>
					</table>
					<?php if(class_exists('Message_Manager')): ?>
					<div id="ekklesia_importer_mm_options">
						<h4>Message Manager Options</h4>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="default_speaker">Default Speaker:</label></th>
								<td><input type="text" name="default_speaker" value="<?php echo $this->get_import_option('default_speaker'); ?>" class="regular-text" />
									<p class="description">Optional. Enter the name of the speaker to be used as the default if the preacher's name is not set in ekklesia.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="default_venue">Default Venue:</label></th>
								<td><input type="text" name="default_venue" value="<?php echo $this->get_import_option('default_venue'); ?>" class="regular-text" />
									<p class="description">Optional. Enter the name of the venue to be used to imported messages.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="default_note_title">Default Note Title:</label></th>
								<td><input type="text" name="default_note_title" value="<?php echo $this->get_import_option('default_note_title'); ?>" class="regular-text" />
									<p class="description">Optional. Enter the default title for imported notes.</p>
								</td>
							</tr>
						</table>
					</div> <!-- end #ekklesia_importer_mm_options -->	
					<?php endif; ?>
					
				</div> <!-- end #ekklesia_importer_options -->
				
				<div id="ekklesia_importer_import">
					<?php display_indicator('import'); ?>
					<h3>Import</h3>
					<div id="ekklesia_importer_action_title"></div>
					<div id="ekklesia_importer_action_details"></div>
					<div id="ekklesia_importer_progress"></div>
					<div id="ekklesia_importer_progress_bar"></div>
				</div> <!-- end ekklesia_importer_import -->
				
				<p id="ekklesia_importer_buttons" class="submit">
					<input type="submit" id="ekklesia_importer_negative_button" name="ekklesia_importer_negative_button" class="button-secondary" value="">
					<input type="submit" id="ekklesia_importer_neutral_button" name="ekklesia_importer_neutral_button" class="button-secondary" value="">
					<input type="submit" id="ekklesia_importer_positive_button" name="ekklesia_importer_positive_button" class="button-primary" value="">
										
					<img id="ekklesia_importer_indicator_img" src="<?php echo Ekklesia_Importer::$url.'img/indicator.gif'; ?>" />
					<span id="ekklesia_importer_indicator_text">Loading...</span>
				</p>
				
			</div><!-- end #ekklesia_importer_ajax -->
		</div> <!-- end wrap -->
		
		<script type="text/javascript">
			//<![CDATA[
			jQuery(document).ready(function($) {

				var lock_key = '<?php echo mt_rand(); ?>';
				
				var in_loop = false;

				var in_command = false;

				// the loop to continuously process information, synchronously
				function doLoop() {
					if (in_loop) return;
					in_loop = true;

					var data = {
						action: 'ekklesia_importer_loop',
						nonce: '<?php echo wp_create_nonce('ekklesia_importer_loop_nonce'); ?>',
						lock_key: lock_key
					};

					$.post(ajaxurl, data, function(response) {
						updateState(response);

						in_loop = false;
						if ('loop' in response) {
							if (response.loop == 'run') {
								doLoop();
							}
						}
					});
				}
				doLoop();

				// give the importer a command
				function doCommand(command) {
					if (in_command) return;
					in_command = true;


					var opts = $('#ekklesia_importer_options :input').serializeArray();
										
					var data = {
						action: 'ekklesia_importer_control',
						nonce: '<?php echo wp_create_nonce('ekklesia_importer_control_nonce'); ?>',
						lock_key: lock_key,
						command: command,
						options: opts
					};

					$.post(ajaxurl, data, function(response) {
						updateState(response);
						in_command = false;
						doLoop();
					});
				}

				// updates the state of the ui based on the response
				function updateState(response) {
					if ('page' in response) {
						changePage(response.page);
					}
					if ('error' in response) {
						if (response.error != '') {
							$('#ekklesia_importer_error').html(response.error);
							$('#ekklesia_importer_error').fadeIn('fast');
						} else {
							$('#ekklesia_importer_error').fadeOut('fast');
						}
					}
					if ('notice' in response) {
						if (response.notice != '') {
							$('#ekklesia_importer_notice').html(response.notice);
							$('#ekklesia_importer_notice').fadeIn('fast');
						} else {
							$('#ekklesia_importer_notice').fadeOut('fast');
						}
					}
					if ('success' in response) {
						if (response.success != '') {
							$('#ekklesia_importer_success').html(response.success);
							$('#ekklesia_importer_success').fadeIn('fast');
						} else {
							$('#ekklesia_importer_success').fadeOut('fast');
						}
					}			
					if ('action' in response) {
						$('#ekklesia_importer_action_title').html(response.action);
					}
					if ('description' in response) {
						$('#ekklesia_importer_action_details').html(response.description);
					}
					if ('progress' in response) {
						$("#ekklesia_importer_progress_bar").progressbar("option", "value", response.progress);
					}
					if ('progress_text' in response) {
						$('#ekklesia_importer_progress').html(response.progress_text);
					}
					if ('loading' in response) {
						$('#ekklesia_importer_indicator_text').text(response.loading);
						$('#ekklesia_importer_indicator_text').fadeIn('fast');
						$('#ekklesia_importer_indicator_img').fadeIn('fase');
					} else {
						$('#ekklesia_importer_indicator_text').fadeOut('fast');
						$('#ekklesia_importer_indicator_img').fadeOut('fast');
					}
					if ('buttons' in response) {
						if ('positive' in response.buttons) {
							$('#ekklesia_importer_positive_button').val(response.buttons.positive);
							$('#ekklesia_importer_positive_button').show();
						} else {
							$('#ekklesia_importer_positive_button').hide();
						}
						if ('neutral' in response.buttons){
							$('#ekklesia_importer_neutral_button').val(response.buttons.neutral);
							$('#ekklesia_importer_neutral_button').show();
						} else {
							$('#ekklesia_importer_neutral_button').hide();
						}
						if ('negative' in response.buttons) {
							$('#ekklesia_importer_negative_button').val(response.buttons.negative);
							$('#ekklesia_importer_negative_button').show();
						} else {
							$('#ekklesia_importer_negative_button').hide();
						}
					}
				}

				// changes the page
				function changePage(page) {
					var pages = ['#ekklesia_importer_lock', '#ekklesia_importer_welcome', '#ekklesia_importer_options', '#ekklesia_importer_import'];
					for (id in pages) {
						if (page != pages[id]) {
							$(pages[id]).hide();
						}
					}
					$(page).fadeIn('fast');
				}

				// set click listeners for commands
				$('#ekklesia_importer_negative_button').click(function() {
					if (confirm("You you sure?")) {
						doCommand('negative');
					}
				});
				$('#ekklesia_importer_neutral_button').click(function() {
					doCommand('neutral');
				});
				$('#ekklesia_importer_positive_button').click(function() {
					doCommand('positive');
				});
				$('#ekklesia_importer_unlock').click(function() {
					doCommand('unlock');
				});

				// make the progress bar
				$('#ekklesia_importer_progress_bar').progressbar({
					value: 0
				});

				// the Message Manager Options visibility
				$('#ekklesia_importer_import_messages').change(function() {
					checkMMOptionsVisibility();
				});

				function checkMMOptionsVisibility() {
					if ($('#ekklesia_importer_import_messages').is(':checked')) {
						$('#ekklesia_importer_mm_options').fadeIn('fast');
					} else {
						$('#ekklesia_importer_mm_options').fadeOut('fast');
					}
				}
				checkMMOptionsVisibility();
			});
			//]]>
		</script>
	<?php 
	} // end _import_page
	
	/** the current loop run status */
	private $loop = 'stop'; // (stop|run|pause)
	
	/** holds the list of actions to complete */
	private $actions = false;
	
	/** holds the current stage */
	private $context = 'welcome';
	
	/** the wp_ajax_ekklesia_importer_command. process commands from the ajax gui */
	function _ajax_control() {
		// check the nonce
		if (wp_verify_nonce($_POST['nonce'], 'ekklesia_importer_control_nonce') === false) {
			$this->die_with_error("Cross Site Scripting Attempt Detected");
		}
		
		// handle errors gracefully
		function ajax_control_error_handler($errno, $errstr, $errfile, $errline ) {
			throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		}
		set_error_handler("ajax_control_error_handler", E_ERROR | E_NOTICE);
		
		// handle the button 
		try {
			if (!empty($_POST['command'])) {				
				$command = $_POST['command'];
				$positive = $_POST['command'] == 'positive';
				$negative = $_POST['command'] == 'negative';
				$neutral = $_POST['command'] == 'neutral';
				
				// check for lock or unlock
				if ($command != 'unlock') {
					$this->check_lock();
				} else {
					$this->set_options('loop', 'pause');
					delete_transient(Ekklesia_Importer::$prefix.'lock');
					$this->die_with_json(array('loading'=>'Unlocking...'));
				}
				
				$this->context = $this->get_option('context', 'welcome');
				
				switch ($this->context) {
					case 'welcome':
						if ($positive) {
							$this->set_options('loop', 'stop');
							$this->set_options('context', 'options');
							$this->die_with_json(array('loading'=>'Loading...'));
						}
						break;
					case 'options': 
						if ($positive) {
							$this->set_options('loop', 'run');
							$this->set_options('context', 'import');
							
							// save the options
							$options = array();
							foreach ($_POST['options'] as $option) {
								$name = str_replace('[]', '', $option['name']);
								$value = $option['value'];
								
								if (!empty($options[$name])) {
									$subopts = $options[$name];
									if (is_array($subopts)) {
										$subopts[] = $value;
									} else {
										$subopts = array($subopts, $value);
									}
									$options[$name] = $subopts;
								} else {
									$options[$name] = $value;
								}
							}
							$this->set_options('options', $options);

							$this->die_with_json(array('loading'=>'Starting Import...'));
						} else if ($negative) {
							$this->reset_import();
							$this->die_with_json(array('loading'=>'Canceling Import...'));
						}
						break;
					case 'import': 
						if ($positive) {
							$this->set_options('loop', 'run');
							$this->set_options('context', 'import');
							$this->die_with_json(array('loading'=>'Starting Import...'));
						} else if ($neutral) {
							$this->set_options('loop', 'pause');
							$this->set_options('context', 'import');
							$this->die_with_json(array('loading'=>'Pausing Import...'));
						} else if ($negative) {
							$this->reset_import();
							$this->die_with_json(array('loading'=>'Canceling Import...'));
						}
						break;
				}
			} else {
				$this->die_with_error("No action specified. Invalid request.");	
			}
		} catch (Exception $e) {
			$this->die_with_error($e->getMessage() . "</br>" . nl2br($e->getTraceAsString(), true));
		}
		
		// if we haven't returned yet, return an empty array
		$this->die_with_json();
	} // end _ajax_control
	
	/** the wp_ajax_ekklesia_importer_loop callback. pretty much everything happens here */
	function _ajax_loop() {
		// check the nonce
		if (wp_verify_nonce($_POST['nonce'], 'ekklesia_importer_loop_nonce') === false) {
			$this->die_with_error("Cross Site Scripting Attempt Detected");
		}
		
		// handle errors gracefully
		function ajax_loop_error_handler($errno, $errstr, $errfile, $errline ) {
			throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		}
		set_error_handler("ajax_loop_error_handler", E_ERROR | E_NOTICE);
		
		try {
			$this->check_lock();
			
			$this->loop = $this->get_option('loop', 'stop');
			$this->actions = $this->get_option('actions', false);
			$this->context = $this->get_option('context', 'welcome');
			
			// make sure the loop is running and we are supposed to be importing
			if ($this->context == 'import' && $this->loop == 'run') {
				
				// init the actions
				if ($this->actions === false) {
					$this->actions = array();
					
					$whats = $this->get_import_option('what', array());
					
					if (is_array($whats)) {
						foreach ($whats as $what) {
							$this->actions[] = array('type'=>$what, 'action'=>'init', 'data'=>array(), 'post_id'=>0);
						}
					} else {
						$this->actions[] = array('type'=>$whats, 'action'=>'init', 'data'=>array(), 'post_id'=>0);
					}
				}
				
				// if the state is empty, but not false, we can assume all tasks have been completed
				if (empty($this->actions)) {
					$this->die_with_success();
				}
				
				// distribute actions based on type
				foreach ($this->actions as $action) {
					switch($action['type']) {
						case 'pages': $this->process_pages($action); break;
						case 'articles': $this->process_articles($action); break;
						case 'messages': $this->process_messages($action); break;
					}
				}
			}

		} catch (Exception $e) {
			$this->die_with_error($e->getMessage() . "</br>" . nl2br($e->getTraceAsString(), true));
		}
		
		// catch all
		$this->die_with_context();
	} // end _ajax_loop
	
	/** checks to make sure only one instance is run at a time */
	function check_lock() {
		$key = get_current_user_id().$_POST['lock_key'];
		$lock = get_transient(Ekklesia_Importer::$prefix.'lock');
		if ($lock !== false && $lock != $key) {
			$this->die_with_json(array('loop'=>'stop', 'page'=>'#ekklesia_importer_lock', 'buttons' => $this->build_button_array()));
		} else {
			set_transient(Ekklesia_Importer::$prefix.'lock', $key, 60*10); // keep lock for 10 min
		}
	}

	function action_completed($actions = array()) {
		// remove the current action from the array
		array_shift($this->actions);
		
		// add the new items to the beginning of the array
		foreach (array_reverse($actions) as $action) {
			array_unshift($this->actions, $action);
		}
	}
	
	/**
	 * Processes import actions for pages
	 * @param $action the action
	 */
	function process_pages($action) {
			
		switch($action['action']) {
			case 'init': 
				$this->init_actions($action['type']);
				break;
			case 'create':
				// extract the values from the array
				list($id, $title, $slug, $url, $groupslug, $description, $tags, $text) = $action['data'];
				
				// report the creation of the page
				$this->report_progress($title, "Importing the page title, tags, content and more.");
				
				// create/check that all of the parent pages exists to attempt to create the structure on ekklesia
				$parts = array_filter(explode('/', $url));
				$tmp_slug = @array_pop($parts);
				
				$path = null; // the processing path
				$parent_id = 0; // the parent page id
				foreach ($parts as $part) {
						
					if (!empty($path)) {
						$path .= '/' . $part;
					} else {
						$path = $part;
					}
						
					$page = get_page_by_path($path, ARRAY_A);
					if (empty($page)) {
						$newpage = array(
							'post_title' => 'PLACEHOLDER: ' . $path,
							'post_content' => 'PLACEHOLDER',
							'post_status' => 'publish',
							'post_type' => 'page',
							'post_name' => $part
						);
						if (!empty($parent_id)) {
							$newpage['post_parent'] = $parent_id;
						}
						$parent_id = wp_insert_post($newpage);
					} else {
						$parent_id = $page['ID'];
					}
				}
				
				// create or update the page
				$page = get_page_by_path($url, ARRAY_A);
				if (empty($page)) {
					$page = array();
				}
				
				$page['post_type'] = 'page';
				$page['post_status'] = 'publish';
				
				if (!empty($title)) {
					$page['post_title'] = wp_strip_all_tags($title);
				}
				if (!empty($tmp_slug)) {
					$page['post_name'] = $tmp_slug;
				} else if (!empty($slug)) {
					$page['post_name'] = $slug;
				}
				if (!empty($description)) {
					$page['post_excerpt'] = $description;
				}
				if (!empty($tags)) {
					$page['tags_input'] = $tags;
				}
				if (!empty($text)) {
					$page['post_content'] = $text;
				}
				if (!empty($parent_id)) {
					$page['post_parent'] = $parent_id;
				}
				
				// insert the page
				$insert = wp_insert_post($page, true);
				
				if (is_wp_error($insert)) {
					$this->add_error($insert->get_error_message());
				} else {
					// add the ekklesia id to the meta data so we can track this later if needed
					add_metadata('post', $insert, '_'.Ekklesia_Importer::$prefix.'id', $id, true);
					add_metadata('post', $insert, '_'.Ekklesia_Importer::$prefix.'raw', $action['data'], true);
				}
				
				$actions = array();
				if ($this->get_import_option('download_media', 'yes') == 'yes') {
					$actions[] = array('type'=>$action['type'], 'action'=>'content', 'data'=>$action['data'], 'post_id'=>$insert);
				}
				
				$this->action_completed($actions);
				break;
				
			case 'content':
				$this->process_post_content_for_attachments($action['post_id']);
				$this->action_completed();
				break;
		}
	}
		
	function process_articles($action) {
		
		switch($action['action']) {
			case 'init': 
				$this->init_actions($action['type']);
				break;
			case 'create': 
				
				// extract the values from the data array
				list($id, $title, $slug, $category, $url, $text, $summary, $tags, $group, $date, $author, $image_url) = $action['data'];
				
				$this->report_progress($title, "Importing the article title, categories, tags, content and more.");
				
				$post = array();
		
				$post['post_type'] = 'post';
				$post['post_status'] = 'publish';
		
				if (!empty($title)) {
					$post['post_title'] = wp_strip_all_tags($title);
				}
				$tmp_slug = $this->get_slug_from_url($url);
				if (!empty($tmp_slug)) {
					$page['post_name'] = $tmp_slug;
				} else if (!empty($slug)) {
					$page['post_name'] = $slug;
				}
				if (!empty($category)) {
					$cat_slug = sanitize_title($category);
					$cat = get_category_by_slug($cat_slug);
					$cat_id;
					if (empty($cat)) {
						$cat_id = wp_insert_category(array(
							'cat_name' => $category,
							'category_nicename' => $cat_slug
						));
					} else {
						$cat_id = $cat->term_id;
					}
					if (!empty($cat_id)) {
						$post['post_category'] = array($cat_id);
					}
				}
				if (!empty($summary)) {
					$post['post_excerpt'] = $summary;
				}
				if (!empty($tags)) {
					$post['tags_input'] = $tags;
				}
				if (!empty($text)) {
					$post['post_content'] = $text;
				}
				if (!empty($date)) {
					$post['post_date'] = $date;
				}
		
				// insert the post
				$insert = wp_insert_post($post, true);
		
				if (is_wp_error($insert)) {
					$this->add_error($insert->get_error_message());
				} else {
					// add the ekklesia id to the meta data so we can track this later if needed
					add_metadata('post', $insert, '_'.Ekklesia_Importer::$prefix.'id', $id, true);
					add_metadata('post', $insert, '_'.Ekklesia_Importer::$prefix.'raw', $action['data'], true);

					$attachment_id = $this->sideload_attachment($image_url, $insert, null, false);
					if (!empty($attachment_id)) {
						update_post_meta($insert, '_thumbnail_id', $attachment_id);
					}
				}
		
				// add content action if download media is yes
				$actions = array();
				if ($this->get_import_option('download_media', 'yes') == 'yes') {
					$actions[] = array('type'=>$action['type'], 'action'=>'content', 'data'=>$action['data'], 'post_id'=>$insert);
				}
				
				$this->action_completed($actions);
				break;
			case 'content': 
				$this->process_post_content_for_attachments($action['post_id']);
				$this->action_completed();
				break;
		}
	}
	
	function process_messages($action) {
		
		switch($action['action']) {
			case 'init': $this->init_actions($action['type']); break;
			case 'create': 
				
				// extract the values from date
				list($id, $title, $date, $category, $series, $series_description, $series_image, $preacher,
						$preacher_slug, $preacher_desc, $preacher_image, $passage_book, $passage_verse, $summary, $tags, $text,
						$audio_url, $video_url, $video_embed, $image_url, $notes_url, $featured) = $action['data'];
				
						
				$this->report_progress($title, "Importing the message title, categories, tags, verses, speakers, content and more.");

				// cpt tax input
				$tax_input = array();
				
				// build tax input topics from tags and category
				if (!empty($category) || !empty($tags)) {
					$tags = array_merge(explode(',', $category), explode(',', $tags));
					$tax_input[Message_Manager::$tax_topics] = array_filter($tags, create_function('$tag','return trim($tag)!="";'));
				}
				
				// build tax input for speaker
				if (!empty($preacher)) {
					$tax_input[Message_Manager::$tax_speaker] = $preacher;
				} else {
					$default = $this->get_import_option('default_speaker', false);
					if (!empty($default)) {
						$tax_input[Message_Manager::$tax_speaker] = $default;
					}
				}
				
				// build tax input for series
				if (!empty($series)) {
					$tax_input[Message_Manager::$tax_series] = $series;
				}
				
				// build tax input for book
				if (!empty($passage_book)) {
					$tax_input[Message_Manager::$tax_books] = $passage_book;
				}
				
				// build tax input for venue
				$default = $this->get_import_option('default_venue', false);
				if (!empty($default)) {
					$tax_input[Message_Manager::$tax_venues] = $default;
				}
			
				// build the post object
				$new_post = array(
					'post_title' => wp_strip_all_tags($title),
					'post_status' => 'publish',
					'post_type' => Message_Manager::$cpt_message,
					'post_date' => $date,
					'post_content' => $text,
					'tax_input' => $tax_input,
				);
				
				// insert the object
				$post_id = wp_insert_post($new_post, true);
				if (is_wp_error($post_id)) {
					$this->add_error($post_id);
					$this->die_with_context();
				}
				
				// add the ekklesia id to the meta data so we can track this later if needed
				add_metadata('post', $post_id, '_'.Ekklesia_Importer::$prefix.'id', $id, true);
				add_metadata('post', $post_id, '_'.Ekklesia_Importer::$prefix.'raw', $action['data'], true);
								
				// set some of the message data
				if (!empty($summary)) {
					Message_Manager::set_message_summary($post_id, $summary);
				}
				if (!empty($passage_book) || !empty($passage_verse)) {
					$verse = $passage_book . ' ' . $passage_verse;
					Message_Manager::set_message_verses($post_id, $verse);
				}
				if (!empty($date)) {
					$date_parts = explode(" ", $date);
					Message_Manager::set_message_date($post_id, $date_parts[0]);
				}
				if (!empty($video_embed)) {
					Message_Manager::add_message_media($post_id, 'embedded', $video_embed);	
				}

				// calculate the rest of the actions for the message
				$actions = array();
				
				// add content action if download media is yes
				$actions = array();
				if ($this->get_import_option('download_media', 'yes') == 'yes') {
					$actions[] = array('type'=>$action['type'], 'action'=>'content', 'data'=>$action['data'], 'post_id'=>$post_id);
				}
				
				// add actions for each media type
				$media = array();
				$media['series image'] = $series_image;
				$media['preacher image'] = $preacher_image;
				$media['audio'] = $audio_url;
				$media['video'] = $video_url;
				$media['image'] = $image_url;
				$media['notes'] = $notes_url;
				$media = array_filter($media);
				
				foreach ($media as $type=>$url) {
					if (!empty($url)) {
						$actions[] = array('type'=>$action['type'], 'action'=>'media', 'media_type'=>$type, 'media_url'=>$url, 'post_id'=>$post_id);
					}
				}
				
				$this->action_completed($actions);
				break;
				
			case 'content': 
				$this->process_post_content_for_attachments($action['post_id']);
				$this->action_completed();
				break;
			case 'media':
				
				$post_id = $action['post_id'];
				$post = get_post($post_id, ARRAY_A);
				
				$media_type = $action['media_type'];
				$media_url = $action['media_url'];
				
				$this->report_progress($post['post_title'], "Importing $media_type from $media_url");
				
				// we need the attachment id for images.
				$attachment_id = $this->sideload_attachment($media_url, $post_id, null, false);
				$attachment_url = wp_get_attachment_url($attachment_id);
				
				if (!empty($attachment_url)) {
					switch($media_type) {
						case 'series image':
							Message_Manager::set_series_image($series_id, $attachment_url);
							break;
						case 'audio':
						case 'video':
							Message_Manager::add_message_media($post_id, 'upload', $attachment_url);
							break;
						case 'image':
							Message_Manager::set_message_image($post_id, $attachment_id);
							break;
						case 'notes':
							$title = $this->get_import_option('default_note_title', 'Message Notes');
							wp_update_post(array('ID'=>$attachment_id, 'post_title'=>$title));
							Message_Manager::add_message_attachment($post_id, 'upload', $attachment_url);
							break;
					}
				}
				
				$this->action_completed();
				break;
		}
	}
	
	function process_post_content_for_attachments($post_id) {
		// get the page
		$post = get_post($post_id, ARRAY_A);
		
		$this->report_progress($post['post_title'], "Processing images and attachments contained in the content.");
		
		// fetch the attachments
		$html = $this->process_post_attachments($post['post_content'], $post_id, true);
		
		// update the content with the rewritten local attachment urls
		return wp_update_post(array('ID'=>$post_id, 'post_content'=>$html));
	}
	
	/** downloads the actions for a type from the remote server */
	function init_actions($type) {
		$url = $this->get_import_option('url');
		$key = $this->get_import_option('key');
		
		if (empty($url)) {
			$this->die_with_bad_option('The export URL is empty');
		}
		
		if (empty($key)) {
			$this->die_with_bad_option('The shared key is empty');
		}
			
		$this->report_progress("Fetching $type", "Geting a list of $type from Ekklesia 360.");
		
		$rand = mt_rand();
		$params = array();
		$params['rand'] = $rand;
		$params['enc'] = sha1($rand.$type.sha1($key));
		$params['what'] = $type;
		
		$url = $url.'?'.http_build_query($params);
		
		$response = wp_remote_get($url, array('timeout'=>60));
		if( is_wp_error($response) ) {
			$this->die_with_bad_option("An error occured while trying to load the remote list of $type. " . $response->get_error_message());
		} else {
			if ($response['response']['code'] == '400' || $response['response']['code'] == '403') {		
				$this->die_with_bad_option("Invalid shared key.");
			} else if ($response['response']['code'] == '404') {
				$this->die_with_bad_option("Invalid export url.");	
			}
			
			$item_delimiter = '~|EKIMPITEM|~';
			$line_delimiter = '~|~EKIMPLINE~|~';
			
			$actions = array();
			
			$lines = explode($line_delimiter, $response['body']);
			foreach ($lines as $line) {								
				if (!empty($line)) {
					$line_array = explode($item_delimiter, $line);
					if (count($line_array) > 0) {
						$clean_line_array = array();
						foreach($line_array as $item) {
							$clean_line_array[] = trim($item);
						}
						
						$actions[] = array('type'=>$type, 'action'=>'create', 'data'=>$clean_line_array, 'post_id'=>0);
					}
				}
			}
			
			$this->action_completed($actions);
			
			$this->die_with_context();
		}
	}
		
	/** side load images in the html content as well as rewrite the html to point to the new urls */
	function process_post_attachments($html, $post_id = -1, $rewrite_html = true) {
		
		require_once Ekklesia_Importer::$path.'includes/simple_html_dom.php';
		
		$parse = str_get_html($html, true, true, DEFAULT_TARGET_CHARSET, false, DEFAULT_BR_TEXT);
		if (method_exists($parse,"find")) {
			
			// process images
			foreach($parse->find('img') as $image) {
				$url = $this->clean_url($image->src);
				
				$filename = basename($url);
				
				// try to parse the filename from the monkimage parameters
				$parts = parse_url($url);
				if(isset($parts['query'])) {
					parse_str(urldecode($parts['query']), $parts['query']);
				
					if (isset($parts['query']['fileName'])) {
						$filename = $parts['query']['fileName'];
					}
				}
				
				// attempt to download the sideload the image
				$attachment_url = $this->sideload_attachment($url, $post_id, $filename);
				if ($attachment_url !== false) {
					if (is_wp_error($attachment_url)) {
						$this->add_error($attachment_url);
						continue;
					} else {
						if ($rewrite_html) {
							$image->src = $attachment_url;
							
							if ($this->get_import_option('responsive_images', 'no') == 'yes') {
								$image->width = null;
								$image->height = null;
							}
						}
					}
				}
			}
			
			// process other attachments (pdf, doc, etc)
			foreach($parse->find('a') as $a) {
				$url = $this->clean_url($a->href);
								
				$attachment_url = $this->sideload_attachment($url, $post_id);
				if ($attachment_url !== false) {
					if (is_wp_error($attachment_url)) {
						$this->add_error($attachment_url);
						continue;
					} else {
						if ($rewrite_html) {
							$a->href = $attachment_url;
						}
					}
				}
			}
			
			// fix memory leaks in parser
			$html = $parse . ''; // append to empty string to make sure html is a string
			$parse->clear();
			unset($parse);
		}

		return $html;
	}
	
	/** cleans a url for download */
	function clean_url($url) {
		$url = trim(esc_url_raw(urldecode($url)));
		$url = str_replace('&amp;', '&', $url);
		return $url;
	}
	
	/** asummes the page slug is the last component of the url */
	function get_slug_from_url($url) {
		$parts = array_filter(explode('/', $url));
		return end($parts);
	}
	
	
	/**
	 * Downloads and sideloads an attachment or image to the media library
	 * @param $url
	 * @param $post_id
	 * @param $filename
	 * @return The attachment url, false if the attachment as not processed or a WP_Error on error.
	 */
	function sideload_attachment($url, $post_id = -1, $filename = null, $return_url = true) {
		// create a file name if null
		if ($filename == null) {
			$filename = basename($url);
		}
		
		// check that the file is allowed
		$check_file = wp_check_filetype($filename, $this->get_allowed_attachment_types());
		$check_url = wp_check_filetype($url, $this->get_allowed_attachment_types());		
		if (!empty($check_file['type']) || !empty($check_url['type'])) {
			// download the file
			$tmp = download_url($url);
			if (is_wp_error($tmp)) {
				@unlink($tmp);
				return $tmp;
			} else {
				// sideload the file
				$id = media_handle_sideload(array('name' => $filename,'tmp_name' => $tmp), $post_id);
	
				// Check for handle sideload errors.
				if (is_wp_error($id)) {
					@unlink($tmp);
					return $id;
				} else {
					if ($return_url) {
						return wp_get_attachment_url($id);
					} else {
						return $id;	
					}
				}
			}
		}
		return false;
	}
	
	/** modify the default allowed types */
	function get_allowed_attachment_types() {
		$types = get_allowed_mime_types();
		unset($types['css']);
		unset($types['htm|html']);
		return $types;
	}
	
	/** 
	 * the default loop end function
	 * 
	 * informs and builds the gui based on errors, state, status, and context
	 * 
	 * @param array $append additional json values
	 */
	function die_with_context($append = array()) {
		// save the actions
		$this->set_options('actions', $this->actions);
		
		$json = array();
		
		// handle errors
		$errors = $this->get_option('errors', array());
		if (!empty($errors)) {
			$json['error'] = '<ul>';
			foreach ($errors as $error) {
				if (is_wp_error($error)) {
					$error = $error->get_error_message();	
				}
				$json['error'] .= '<li>'.$error.'</li>';
			}
			$json['error'] .= '</ul>';
		}
		
		// add the loop status
		$this->loop = $this->get_option('loop', 'stop');
		$json['loop'] = $this->loop;
		
		// add the context - set the page and buttons
		$this->context = $this->get_option('context', 'welcome');
		switch($this->context) {
			case 'welcome': 
				$json['page'] = '#ekklesia_importer_welcome';
				$json['buttons'] = $this->build_button_array('Get Started!');
				break;
			case 'options' : 
				$json['page'] = '#ekklesia_importer_options';
				$json['buttons'] = $this->build_button_array('Start Import', 'Cancel Import');
				break;
			case 'import' : 
				$json['page'] = '#ekklesia_importer_import';
				if ($this->loop == 'run') {
					$json['buttons'] = $this->build_button_array(null, 'Cancel Import', 'Pause Import');
				} else {
					$json['buttons'] = $this->build_button_array('Continue Import', 'Cancel Import');
				}
				break;			
		}
		
		$this->die_with_json(array_merge($json, $append));
	}
	
	/** dies with a json error */
	function die_with_error($error) {
		// we don't modify any options here, as this could be reached before the nonce or lock is checked
		$this->die_with_json(array('error' => $error, 'loop'=>'stop', 'buttons'=>$this->build_button_array(null, 'Cancel Import')));	
	}
	
	function die_with_success($append = array()) {
		$this->reset_import();
		$json = array('success'=>'Import Completed.');
		$this->die_with_context(array_merge($json, $append));
	}
	
	function die_with_bad_option($message) {
		$this->set_options('loop', 'stop');
		$this->set_options('context', 'options');
		$this->die_with_context(array('error'=>$message));
	}
	
	/** clears the output buffer and sends the json response */
	function die_with_json($json = array()) {
		ob_clean();
		header("Content-Type: application/json");
		header("Cache-Control: no-cache, must-revalidate");
		header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
		header("Pragma: no-cache");
		die(json_encode($json));
	}
	
	/** Reports the progess of an action and keeps track of the last reported action */
	function report_progress($action = null, $description = null, $progress = null, $progress_text = null) {
		$last = $this->get_option('last_progress');
	
		$hash = sha1($action.$description.$progress.$progress_text);
	
		// check if we already reported this
		if ($hash != $last) {
			$json = array();
			if (!empty($action)) {
				$json['action'] = $action;
			}
			if (!empty($description)) {
				$json['description'] = $description;
			}
			
			// attemps to calculate the progress
			if (empty($progress) && empty($progress_text)) {
				if (is_array($this->actions)) {
					if (count($this->actions > 0)) {
						$pages = 0;
						$articles = 0;
						$messages = 0;
						foreach ($this->actions as $action) {
							if ($action['action'] == 'create') {
								switch($action['type']) {
									case 'pages': $pages++; break;
									case 'articles': $articles++; break;
									case 'messages': $messages++; break;
								}
							}
						}
						
						extract($this->get_option('progress', array('max_pages'=>0, 'max_articles'=>0, 'max_messages'=>0)));
						if ($pages > $max_pages) $max_pages = $pages;
						if ($articles > $max_articles) $max_articles = $articles;
						if ($messages > $max_messages) $max_messages = $messages;
						$this->set_options('progress', compact('max_pages', 'max_articles', 'max_messages'));
						
						$inits = 1;
						$whats = $this->get_import_option('what', array());
						if (is_array($whats)) {
							$inits = count($whats);	
						}
						
						$progress = 0;
						$progress_text = "";
						if ($pages && $max_pages) {
							$completed = $max_pages-$pages;
							$progress_text .= "Pages: $completed/$max_pages ";
							$progress += ($completed/$max_pages)/$inits;
						}
						if ($articles && $max_articles) {
							$completed = $max_articles-$articles;
							$progress_text .= "Articles: $completed/$max_articles ";
							$progress += ($completed/$max_articles)/$inits;
						}
						if ($messages && $max_messages) {
							$completed = $max_messages-$messages;
							$progress_text .= "Messages: $completed/$max_messages ";
							$progress += ($completed/$max_messages)/$inits;
						}
						$progress *= 100;
					}
				}
			}
			
			if (!empty($progress)) {
				$json['progress'] = $progress;
			}
			if (!empty($progress_text)) {
				$json['progress_text'] = $progress_text;
			}
				
			// set the hash so it isn't reported on the next run
			$this->set_options('last_progress', $hash);

			$this->die_with_context($json);
		}
	}
	
	/** creates an array with the button names */
	function build_button_array($positive = null, $negative = null, $neutral = null) {
		$buttons = array();
		if ($positive != null) {
			$buttons['positive'] = $positive;
		}
		if ($negative != null) {
			$buttons['negative'] = $negative;
		}
		if ($neutral != null) {
			$buttons['neutral'] = $neutral;
		}
		return $buttons;
	}
	
	/** deletes all options created by the importer */
	function reset_import() {
		$this->actions = false;
		$this->options = false;
		$this->delete_option('loop');
		$this->delete_option('actions');
		$this->delete_option('context');
		$this->delete_option('errors');
		$this->delete_option('progress');
		$this->delete_option('last_progress');
		delete_transient(Ekklesia_Importer::$prefix.'lock');
	}
	
	/** stores an error to be picked up by the end of loop methods */
	function add_error($error) {
		$errors = $this->get_option('errors', array());
		$errors[] = $error;
		$this->set_options('errors', $errors);
	}
	
	/** clears all of the errors */
	function clear_errors() {
		$this->delete_option('errors');
	}
	
	/** appends the prefix to the name and returns the value */
	function get_option($name, $default = false) {
		return get_option(Ekklesia_Importer::$prefix.$name, $default);	
	}
	
	/** appends the prefix to the name and sets the option */
	function set_options($name, $value) {
		return update_option(Ekklesia_Importer::$prefix.$name, $value);
	}
	
	/** appends the prefix to the name and deletes the option */
	function delete_option($name) {
		return delete_option(Ekklesia_Importer::$prefix.$name);	
	}
	
	/** the importer options */
	private $options = null;
	
	/** gets an option from the options option */
	function get_import_option($option, $default = false) {
		$this->options = $this->get_option('options', array());
		if (array_key_exists($option, $this->options)) {
			return $this->options[$option];
		}
		return $default;
	}
}
new Ekklesia_Importer();