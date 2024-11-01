<?php
/**
 *
 * Plugin Name: Mezzobit Trusted Data Interchange
 * Plugin URI: http://wordpress.org/extend/plugins/mezzobit-trusted-data-interchange/
 * Description: The Mezzobit plug-in permits our tag management system to manage all of the third-party JavaScript data collection tags on your WordPress site. We work with a data privacy non-profit, DataNeutrality.org, to create the Internet's first socially responsible data collection platform, ensuring that your data is handled with care and your users' privacy is respected.
 * Version: 1.0
 * Author: Mezzobit
 * Author URI: http://www.mezzobit.com
 * License: GPLv2 or later
 *
 * Installation:
 *
 * 1. Download and unzip the latest release zip file.
 * 2. If you use the WordPress plugin uploader to install this plugin skip to step 4.
 * 3. Upload the entire plugin directory to your `/wp-content/plugins/` directory.
 * 4. Activate the plugin through the 'Plugins' menu in WordPress Administration.
 *
 * Usage:
 *
 * 1. Go to Settings -> Mezzobit TDI
 * 2. Register for Mezzobit TDI in case you didn't yet
 * 3. Select the container from the list or input the container ID manually
 * 4. Save
 *
 */

class WordpressMezzobitTagManager {

  const VERSION = '1.0';
  const OAUTH_CLIENT_ID = 'mezzobit-wp-plugin';
  const OAUTH_CLIENT_SECRET = ':~$A!8{,Sqbaa+]/g55h76%!zv4-_I.9nmZxvNu[;Tnufsr[yu7`G^y6#lad$N^';

  const OAUTH_AUTH_ENDPOINT = 'http://tdi.mezzobit.com/oauth/authorize';
  const OAUTH_TOKEN_ENDPOINT = 'http://tdi.mezzobit.com/oauth/access_token';
  const OAUTH_PERMISSIONS = 'USER:LOGIN-INFO,TAG-CONTAINER:AUTOCOMPLETE';

  private $containerId;
  private $updated = false;
  private $called = false;

  /**
   * @return void
   *
   * register settings subpage<br>
   * register header output
   */
  public function WordpressMezzobitTagManager() {
    load_plugin_textdomain('wordpress_mezzobit_tag_manager', false, basename(__DIR__) . '/languages');
    $this->containerId = get_option('wordpress_mezzobit_tag_manager_container_id', '');
    add_action('admin_init', array($this, 'admin_init'));
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('wp_head', array($this, 'output_container'));
  }

  private static function get_oauth_client() {
    require_once 'oauth-client/Client.php';
    return new Mezzobit_OAuth2_Client(self::OAUTH_CLIENT_ID, self::OAUTH_CLIENT_SECRET);
  }

  private static function get_oauth_auth_uri() {
    $redirectURI = 'http' . ($_SERVER['HTTPS'] ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] .
        ($_SERVER['SERVER_PORT'] != ($_SERVER['HTTPS'] ? 443 : 80) ? ':' . $_SERVER['SERVER_PORT'] : '') . $_SERVER['REQUEST_URI'] .
        (strpos($_SERVER['REQUEST_URI'], '?') === false ? '?' : '&') . 'wordpress_mezzobit_tag_manager_oauth_callback=1&';
    return self::get_oauth_client()->getAuthenticationUrl(self::OAUTH_AUTH_ENDPOINT, $redirectURI) . '&singlestep=1&perms=' . self::OAUTH_PERMISSIONS;
  }

  private static function get_oauth_logout_uri() {
    return $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') === false ? '?' : '&') . 'wordpress_mezzobit_tag_manager_oauth_logout=1';
  }

  private function oauth_callback() {
    if (isset($_GET['wordpress_mezzobit_tag_manager_oauth_callback']) && $_GET['wordpress_mezzobit_tag_manager_oauth_callback'] == 1) {
      // Process the OAuth callback
      // The server appends "?" to the URL regardless of the query part presence, workaround this
      foreach ($_GET as $key => $val) {
        if ($key[0] == '?') {
          unset($_GET[$key]);
          $_GET[substr($key, 1)] = $val;
          break;
        }
      }
      if (isset($_GET['code'])) {
        $client = self::get_oauth_client();
        // redirect_uri is unused at this stage, though required by the protocol, so provide a stub
        $params = array('code' => $_GET['code'], 'redirect_uri' => 'http://www.example.com');
        try {
          $response = $client->getAccessToken(self::OAUTH_TOKEN_ENDPOINT, 'authorization_code', $params);
          if (is_array($response['result']) && isset($response['result']['access_token']) && is_string($response['result']['access_token'])) {
            update_option('wordpress_mezzobit_tag_manager_access_token', $response['result']['access_token']);
          }
        } catch (Mezzobit_OAuth2_Exception $e) {
          // Ignore the callback communication errors for now
        }
        $redirect = preg_replace('/[&\\?]wordpress_mezzobit_tag_manager_oauth_callback=1.*$/', '', $_SERVER['REQUEST_URI']);
        die('<script>if (window.opener) { window.opener.location = "' . $redirect . '"; window.close() } else { location = "' . $redirect . '" }</script>');
      }
    } elseif (isset($_GET['wordpress_mezzobit_tag_manager_oauth_logout'])) {
      // Do the logout
      delete_option('wordpress_mezzobit_tag_manager_access_token');
      header('Location: ' . preg_replace('/[&\\?]wordpress_mezzobit_tag_manager_oauth_logout=.*$/', '', $_SERVER['REQUEST_URI']));
      die();
    }
  }

  /**
   * @param $message
   * @param bool $errormsg
   * @return void
   *
   * Shows Errormessage in Backend
   */
  private function show_message($message, $errormsg = false)
  {
    if ($errormsg) {
      echo '<div id="message" class="error">';
    }
    else {
      echo '<div id="message" class="updated fade">';
    }
    ?><p><strong><?php echo $message; ?></strong><a style="float:right" href="?wordpress_mezzobit_tag_manager_ignore_notice=0"><?php esc_html_e('Dismiss', 'wordpress_mezzobit_tag_manager'); ?></a></p></div><?php
  }

  /**
   * @return void
   *
   * Calls show_message with specific warning, missing id
   */
  public function show_missing_id_warning()
  {
    global $current_user;
    $userId = $current_user->ID;
    /* Check that the user hasn't already clicked to ignore the message */
    if (!get_user_meta($userId, 'wordpress_mezzobit_tag_manager_ignore_notice') && current_user_can('manage_options')) {
      $this->show_message(__('Mezzobit TDI is missing the container ID. <a href="/wp-admin/options-general.php?page=wordpress_mezzobit_tag_manager">Fix this!</a>', 'wordpress_mezzobit_tag_manager'), true);
    }
  }

  /**
   * @return void
   *
   * admin_init hook
   */
  public function admin_init() {
    $this->oauth_callback();

    wp_enqueue_script('thickbox', null, array('jquery'));
    wp_enqueue_style('thickbox.css', '/' . WPINC . '/js/thickbox/thickbox.css', null, '1.0');

    global $current_user;
    $userId = $current_user->ID;
    /* If user clicks to ignore the notice, add that to their user meta */
    if (isset($_GET['wordpress_mezzobit_tag_manager_ignore_notice']) && $_GET['wordpress_mezzobit_tag_manager_ignore_notice'] == '0') {
      add_user_meta($userId, 'wordpress_mezzobit_tag_manager_ignore_notice', 'true', true);
    }
  }


  /**
   * @return void
   *
   * admin_menu hook
   */
  public function admin_menu() {
    $this->update_container_id();
    global $pagenow;
    if ($this->containerId == '' && $pagenow == 'index.php') {
      add_action('admin_notices', array($this, 'show_missing_id_warning'));
    }
    $title = __('Mezzobit TDI', 'wordpress_mezzobit_tag_manager');
    add_options_page($title, $title, 'manage_options', 'wordpress_mezzobit_tag_manager', array($this, 'settings_menu'));
  }

  /**
   * @return void
   *
   * update container_id
   */
  private function update_container_id() {
    if (isset($_POST['wordpress_mezzobit_tag_manager_container_id'])) {
      $containerId = $_POST['wordpress_mezzobit_tag_manager_container_id'];
      if (preg_match('/^[0-9a-f]{24,24}$/i', $containerId) && $containerId !== $this->containerId) {
        if (update_option('wordpress_mezzobit_tag_manager_container_id', $containerId)) {
          if (isset($_POST['wordpress_mezzobit_tag_manager_container_name']) && isset($_POST['wordpress_mezzobit_tag_manager_container_descr'])) {
            update_option('wordpress_mezzobit_tag_manager_container_descr', serialize(
                            array((string)$_POST['wordpress_mezzobit_tag_manager_container_name'], (string)$_POST['wordpress_mezzobit_tag_manager_container_descr'])
                          ));
          } else {
            delete_option('wordpress_mezzobit_tag_manager_container_descr');
          }
          $this->containerId = $containerId;
          $this->updated = true;
        }
      }
    }
  }

  private function render_settings_id_input($loggedIn) {
    $descr = get_option('wordpress_mezzobit_tag_manager_container_descr');
    if ($descr) {
      $descr = @unserialize($descr);
    }
    $placeholder = esc_html__('Enter the hexadecimal 24-digit ID here', 'wordpress_mezzobit_tag_manager');
    ?>
        <style scoped>
        #wordpress_mezzobit_tag_manager_container_id { margin-right: 5px }
        #wordpress_mezzobit_tag_manager_container_id:invalid { border-color : #dfa5a5; color : #6A0000 }
        </style>
        <form method="POST" id="wordpress_mezzobit_tag_manager_form"<?php if ($descr) echo ' autocomplete="off"'; ?>>
          <table class="form-table">
            <tr valign="middle">
              <th scope="row"><label for="wordpress_mezzobit_tag_manager_container_id"><?php esc_html_e('Container ID', 'wordpress_mezzobit_tag_manager'); ?></label></th>
              <td>
              <input type="text" spellcheck="false" size="40"<?php
                if ($descr) {
                  echo ' data-original="', $this->containerId, '"';
                } else {
                  echo ' maxlength="24" pattern="^[0-9a-fA-F]{24,24}$"';
                }
              ?> id="wordpress_mezzobit_tag_manager_container_id" name="wordpress_mezzobit_tag_manager_container_id" placeholder="<?php echo $placeholder; ?>" oninvalid="this.setCustomValidity('<?php echo $placeholder; ?>')" value="<?php echo $descr ? esc_html($descr[0]) : $this->containerId; ?>">
              <input type="button" class="button" id="wordpress_mezzobit_tag_manager_edit_btn" value="<?php esc_attr_e('Edit container', 'wordpress_mezzobit_tag_manager'); ?>">
              <?php $this->render_login_button($loggedIn); ?>
              </td>
            </tr>
            <?php if ($descr) { ?>
            <tr valign="middle"><td>&nbsp;<td><span id="wordpress_mezzobit_tag_manager_container_descr"><?php echo esc_html($descr[1]); ?></span><span class="wordpress_mezzobit_tag_manager_container_id_disp">(ID: <span id="wordpress_mezzobit_tag_manager_container_id_disp"><?php echo $this->containerId; ?></span>)</span></tr>
            <?php } ?>
            <tr valign="top">
              <th scope="row">&nbsp;</th>
              <td><input type="submit" class="button-primary" value="<?php esc_attr_e('Save changes', 'wordpress_mezzobit_tag_manager'); ?>"></td>
            </tr>
          </table>
        </form>
        <script type="text/javascript">
        jQuery(function ($) {
          var btn = $("#wordpress_mezzobit_tag_manager_edit_btn");
          var inp = $("#wordpress_mezzobit_tag_manager_container_id");
          var idDisp = $(".wordpress_mezzobit_tag_manager_container_id_disp");
          var inpElem = inp.get(0);
          <?php if ($descr) { ?>
          var removed = false;
          var focusHandler = function () {
            inp.val("<?php echo $this->containerId; ?>");
            inp.attr("maxlength", "24").attr("pattern", "^[0-9a-fA-F]{24,24}$");
            idDisp.hide();
          };
          var blurHandler = function () {
            inp.removeAttr("maxlength").removeAttr("pattern");
            inp.val(<?php echo json_encode($descr[0]); ?>);
            idDisp.show();
          };
          inp.bind("focus", focusHandler).bind("blur", blurHandler);
          <?php } ?>
          var buttonEnabler = function (e) {
          <?php if ($descr) { ?>
            if (e && !removed) {
              idDisp.parents("tr").eq(0).remove();
              inp.attr("maxlength", "24").attr("pattern", "^[0-9a-fA-F]{24,24}$");
              inp.unbind("focus", focusHandler).unbind("blur", blurHandler);
              inp.data("original", null);
              removed = true;
            }
          <?php } ?>
            if (inpElem.setCustomValidity) {
              inpElem.setCustomValidity("");
            }
            if (!inp.val()) {
              btn.attr("disabled", true);
            } else if (inpElem.checkValidity && !inpElem.checkValidity()) {
              btn.attr("disabled", true);
              inp.trigger("invalid");
            } else {
              btn.removeAttr("disabled");
            }
          };
          inp.bind("input", buttonEnabler).change(buttonEnabler);
          buttonEnabler();
          btn.click(function () {
            var id = inp.data("original") || inp.val();
            window.open("http://tdi.mezzobit.com/tag-container/" + id + "/update", "mzbt-tdi-tab");
          });
          $("#wordpress_mezzobit_tag_manager_form").submit(function () {
            <?php if ($descr) { ?>
            if (!removed) {
              return false;
            }
            <?php } ?>
            if (!inp.val()) {
              return false;
            }
          });
        });
        </script>
    <?php
  }

  private function anon_settings_menu() {
    ?>
    <p>To configure WordPress to use the TDI tag manager, you must first have a TDI account. If you haven't yet registered, <a target="mzbt-tdi-tab" href="http://tdi.mezzobit.com/register/tiny">click here to create a free account</a>.</p>
    <p>Associate your TDI and WordPress accounts by clicking on the "Link WordPress to TDI" button or by <a class="wordpress_mezzobit_tag_manager_login" href="javascript:void(0)">clicking here</a>.</p>
    <p>You may just enter the TDI container ID in the text box below (to obtain the container ID, select some container <a target="mzbt-tdi-tab" href="http://tdi.mezzobit.com/tag-container">from the list</a>, its ID would be displayed just below its name in the editing dialog). Once your account is linked, you can select the TDI container from the dropdown list.</p>
    <p>A container is used to call in the third-party data collection tags. For more information on how TDI works, please consult <a target="mzbt-tdi-tab" href="http://support.mezzobit.com">our knowledge base</a>.</p>
    <script>
    jQuery(function ($) {
      $(".wordpress_mezzobit_tag_manager_login").click(function () {
        window.open("<?php echo self::get_oauth_auth_uri(); ?>", "mzbt-tdi-login", "width=700,height=710,centerscreen=1,menubar=0,toolbar=0,location=1,personalbar=0,status=0,resizable=0");
        return false;
      });
    });
    </script>
    <?php
    $this->render_settings_id_input(false);
  }

  private function render_login_button($loggedIn) {
    ?><input type="button" value="<?php esc_attr_e($loggedIn ? 'Logout' : 'Link WordPress to TDI'); ?>" class="button<?php
    if ($loggedIn) {
      echo '" onclick=\'location="', esc_attr(self::get_oauth_logout_uri()), '"\'>';
    } else {
      echo ' wordpress_mezzobit_tag_manager_login">';
    }
  }

  private function auth_settings_menu($login, $containers) {
    if (!is_array($containers)) {
      ?>
      <p>Unable to access the Mezzobit TDI account to get the list of containers. You may still enter the container ID in the text box below (to obtain the container ID select some container <a target="mzbt-tdi-tab" href="http://tdi.mezzobit.com/tag-container">from the list</a>, its ID would be displayed just below its name in the editing dialog).</p>
      <?php
      $this->render_settings_id_input(true);
    } else {
      ?>
      <p>You are logged in as <b><?php echo esc_html($login); ?></b> (<a href="<?php echo esc_attr(self::get_oauth_logout_uri()); ?>">logout</a>). 
      <?php
      if (!sizeof($containers)) {
        ?>
        Seems like you have no containers yet, you may <a href="http://tdi.mezzobit.com/tag-container">create containers via the Mezzobit Tag Manager web interface</a> (please reload this page after that).</p>
        <p>A container is used to call in the third-party data collection tags. For more information on how TDI works, please consult <a target="mzbt-tdi-tab" href="http://support.mezzobit.com">our knowledge base</a>.</p>
        <?php
      } else {
        // Switch on select2
        wp_register_script('select2', plugins_url('assets/js/select2/select2.js', __FILE__), array('jquery'), self::VERSION, true);
        wp_register_style('select2', plugins_url('assets/js/select2/select2.css', __FILE__));
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');
        ?>
        Please select the container from the list below.</p>
        <p>A container is used to call in the third-party data collection tags. For more information on how TDI works, please consult <a target="mzbt-tdi-tab" href="http://support.mezzobit.com">our knowledge base</a>.</p>
        <style scoped>
        select, .select2-container { width: 280px; vertical-align: middle; margin-right: 8px }
        input[type=button] { height: 28px; vertical-align: middle }
        </style>
        <form method="POST" id="wordpress_mezzobit_tag_manager_form">
          <table class="form-table">
            <tr valign="middle">
              <th scope="row"><label for="wordpress_mezzobit_tag_manager_container_id"><?php esc_html_e('Container', 'wordpress_mezzobit_tag_manager'); ?></label></th>
              <td><select id="wordpress_mezzobit_tag_manager_container_id" name="wordpress_mezzobit_tag_manager_container_id" placeholder="Select a container">
                <option data-html=""></option>
                <?php foreach ($containers as $container) {
                  $name = esc_html($container['name']);
                  $descr = esc_html($container['description']);
                ?>
                <option data-descr="<?php echo $descr; ?>" data-html="<?php echo esc_attr(sprintf('<div class=data>%s</div><small>%s</small>', $name, $descr)); ?>" value="<?php echo $container['id']; ?>"<?php if ($this->containerId == $container['id']) echo ' selected'; ?>><?php echo $name; ?></option>
                <?php } ?>
               </select><input type="button" class="button" id="wordpress_mezzobit_tag_manager_edit_btn" value="<?php esc_attr_e('Edit container', 'wordpress_mezzobit_tag_manager'); ?>">
               <?php $this->render_login_button(true); ?></td>
            </tr>
            <tr valign="middle"><td>&nbsp;<td><span id="wordpress_mezzobit_tag_manager_container_descr"></span><span class="wordpress_mezzobit_tag_manager_container_id_disp">(ID: <span id="wordpress_mezzobit_tag_manager_container_id_disp"></span>)</span></tr>
            <input type="hidden" name="wordpress_mezzobit_tag_manager_container_descr" id="wordpress_mezzobit_tag_manager_container_descr_inp">
            <input type="hidden" name="wordpress_mezzobit_tag_manager_container_name" id="wordpress_mezzobit_tag_manager_container_name_inp">
            <tr valign="top">
              <th scope="row">&nbsp;</th>
              <td><input type="submit" class="button-primary" value="<?php esc_attr_e('Save changes', 'wordpress_mezzobit_tag_manager'); ?>"></td>
            </tr>
          </table>
        </form>
        <script type="text/javascript">
        jQuery(function ($) {
          var btn = $("#wordpress_mezzobit_tag_manager_edit_btn");
          var inp = $("#wordpress_mezzobit_tag_manager_container_id");
          var descr = $("#wordpress_mezzobit_tag_manager_container_descr");
          var descrCont = descr.parents("tr").eq(0);
          var descrInput = $("#wordpress_mezzobit_tag_manager_container_descr_inp");
          var nameInput = $("#wordpress_mezzobit_tag_manager_container_name_inp");
          var idDisp = $("#wordpress_mezzobit_tag_manager_container_id_disp");
          inp.select2();
          var buttonEnabler = function () {
            var val = inp.val();
            if (!val) {
              btn.attr("disabled", true);
              descrCont.hide();
            } else {
              btn.removeAttr("disabled");
              var option = inp.children("[value=" + val + "]");
              var descrText = option.data("descr");
              descr.text(descrText);
              nameInput.val(option.text());
              idDisp.text(val);
              descrInput.val(descrText);
            }
          };
          inp.change(buttonEnabler);
          buttonEnabler();
          btn.click(function () {
            window.open("http://tdi.mezzobit.com/tag-container/" + inp.val() + "/update", "mzbt-tdi-tab");
          });
          $("#wordpress_mezzobit_tag_manager_form").submit(function () {
            if (!inp.val()) {
              return false;
            }
          });
        });
        </script>
        <?php
      }
    }
  }

  /**
   * @return void
   *
   * Prints the Backend Settings Page
   */
  public function settings_menu() {
    if (!$this->containerId) {
    ?>
        <script type="text/javascript">
        jQuery(function ($) {
          $.get(location.toString().replace(/\/wp-admin\/.*$/, ""), {}, function (data) {
            if (/<[sS][cC][rR][iI][pP][tT][\s\n\r>][^<]*\b__mtm[\s\n\r]*=[\s\n\r]/.test(data)) {
              $("#wordpress_mezzobit_tag_manager_wrap").prepend("<div id='message' class='error'><p>Seems like your blog already has a Mezzobit Tag Manager container installed somehow (perhaps by placing the code into the Wordpress template code or just adding it to the blog post HTML). Two containers installed on the same web page won't work together, please remove the manually installed container before selecting the one in this plugin</p></div>");
            }
          }, "text");
        });
        </script>
    <?php
    }
    ?>
      <div class="wrap" id="wordpress_mezzobit_tag_manager_wrap">
        <style scoped>
        #wordpress_mezzobit_tag_manager_hdr { background: url(http://tdi.mezzobit.com/images/favicon.png) no-repeat center left; padding: 6px 15px 4px 24px; margin-top: 3px }
        #wordpress_mezzobit_tag_manager_edit_btn { margin-right: 5px }
        #wordpress_mezzobit_tag_manager_container_descr { font-size: 11px; font-style: italic }
        .wordpress_mezzobit_tag_manager_container_id_disp { font-weight: bold; padding-left: 10px }
        </style>
        <?php if ($this->updated) { ?>
        <div class="updated"><p><?php esc_html_e('The settings were saved succesfully', 'wordpress_mezzobit_tag_manager'); ?></p></div>
        <?php } ?>
        <h2 id="wordpress_mezzobit_tag_manager_hdr"><?php esc_html_e('Mezzobit TDI', 'wordpress_mezzobit_tag_manager'); ?></h2>
    <?php
      $oauthToken = get_option('wordpress_mezzobit_tag_manager_access_token');
      if ($oauthToken !== false) {
      $client = self::get_oauth_client();
      $client->setAccessToken($oauthToken);
      try {
        $response = $client->fetch('http://tdi.mezzobit.com/tag-container/autocomplete', array(), Mezzobit_OAuth2_Client::HTTP_METHOD_GET, array('X-Requested-With' => 'xmlhttprequest'));
      } catch (Mezzobit_OAuth2_Exception $e) {
        // Consider the connection error
        $response = array('code' => 200, 'result' => false);
      }
      if ($response['code'] === 403) {
        // Something is wrong with the token, invalidate it
        $oauthToken = false;
        delete_option('wordpress_mezzobit_tag_manager_access_token');
      } else {
        if ($response['code'] === 200 && is_array($response['result'])) {
          $containers = $response['result'];
        } else {
          $containers = false;
        }
        try {
          $response = $client->fetch('http://tdi.mezzobit.com/user/profile/login-info', array(), Mezzobit_OAuth2_Client::HTTP_METHOD_GET, array('X-Requested-With' => 'xmlhttprequest'));
        } catch (Mezzobit_OAuth2_Exception $e) {
          // Consider the connection error
          $response = array('code' => 200, 'result' => false);
        }
        if ($response['code'] === 200 && is_array($response['result']) && isset($response['result']['login'])) {
          $login = $response['result']['login'];
        } else {
          $login = 'unknown';
        }
      }
    }
    if ($oauthToken === false) {
      $this->anon_settings_menu();
    } else {
      $this->auth_settings_menu($login, $containers);
    }
    ?>
      </div>
    <?php
  }

  public function output_manual() {
    if (!$this->called) {
      $this->output_container();
    }
  }

  /**
   * @return void
   *
   * print the container code into the head
   */
  public function output_container() {
    if ($this->called) { // do not output again
      return;
    } else {
      $this->called = true;
    }
    if ($this->containerId) {
      ?><script async>
(function() {
  __mtm = [ '<?php echo $this->containerId; ?>', 'd36wtdrdo22bqa.cloudfront.net/mngr', 'tdi.mezzobit.com' ];
  var s = document.createElement('script');
  s.async = 1;
  s.src = '//' + __mtm[1] + '/mtm.js';
  var e = document.getElementsByTagName('script')[0];
  (e.parentNode || document.body).insertBefore(s, e);
})();
</script>
<?php
    }
  }
}

global $wordpress_mezzobit_tag_manager;
$wordpress_mezzobit_tag_manager = new WordpressMezzobitTagManager();
