<?php
// Executable smoke test without WordPress. No production PII is used.
define('ABSPATH', '/tmp/fresh-core-test/');
function add_action($hook, $callback) {}
function register_rest_route($ns, $path, $args) {
    $GLOBALS['routes'][$path] = $args;
}
function home_url($path='/') { return 'https://demo.example.cz/'; }
function get_user_meta($id, $key, $single) { return $key === 'first_name' ? 'Jan' : ($key === 'last_name' ? 'Test' : ''); }
function get_userdata($id) { return (object)['ID'=>$id]; }
function user_can($user, $cap) { return $user->ID === 2; }
function sanitize_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : ''; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($v) { return strip_tags($v); }
function wp_strip_all_tags($v) { return strip_tags($v); }
function get_permalink($id) { return 'https://demo.example.cz/form'; }
class WP_REST_Request {
    private $args;
    function __construct($args) { $this->args = $args; }
    function get_param($key) { return $this->args[$key] ?? null; }
}
class WP_REST_Response {
    public $data;
    function __construct($data,$status=200) { $this->data=$data; }
    function header($key,$value) {}
}
class WP_Error {
    public $code;
    function __construct($code,$message,$data) { $this->code=$code; }
}
class Fresh_Core_Accounting {
    public static function permission_or_error($r) { return true; }
}
class TestDB {
    public $prefix='wp_';
    public $users='wp_users';
    public $rows=[];
    function prepare($query,...$args) { return $query; }
    function get_var($query) { return strpos($query, 'TABLES')!==false ? 'wp_e_submissions' : ''; }
    function get_results($query) {
        if (strpos($query,'FROM wp_users')!==false) {
            return [(object)['ID'=>1,'user_email'=>'jan@example.cz','display_name'=>'Jan','user_registered'=>'2026-01-01'],
                    (object)['ID'=>2,'user_email'=>'admin@example.cz','display_name'=>'Admin','user_registered'=>'2026-01-02']];
        }
        if (strpos($query,'e_submissions_values')!==false) {
            return [(object)['submission_id'=>8,'key'=>'name','value'=>'Test Klient'],
                    (object)['submission_id'=>8,'key'=>'email','value'=>'form@example.cz'],
                    (object)['submission_id'=>8,'key'=>'message','value'=>'PRIVATE LEAD MESSAGE'],
                    (object)['submission_id'=>8,'key'=>'phone','value'=>'777111222']];
        }
        if (strpos($query,'e_submissions')!==false) {
            return [(object)['id'=>8,'post_id'=>99,'form_name'=>'Poptavka','created_at'=>'2026-10-01']];
        }
        return [];
    }
}
$wpdb=new TestDB();
require __DIR__ . '/../includes/class-fresh-core-crm.php';
Fresh_Core_CRM::routes();
assert(isset($GLOBALS['routes']['/accounting/contacts']));
assert($GLOBALS['routes']['/accounting/contacts']['permission_callback'][0] === 'Fresh_Core_Accounting');
$users=Fresh_Core_CRM::contacts(new WP_REST_Request(['kind'=>'users','after_id'=>0,'limit'=>50]));
assert($users instanceof WP_REST_Response);
assert($users->data['items'][0]['email']==='jan@example.cz');
assert($users->data['items'][1]['skip']===true);
$forms=Fresh_Core_CRM::contacts(new WP_REST_Request(['kind'=>'elementor_submissions','after_id'=>0,'limit'=>50]));
assert($forms instanceof WP_REST_Response);
assert($forms->data['items'][0]['email']==='form@example.cz');
assert(!array_key_exists('message', $forms->data['items'][0]));
assert(strpos(json_encode($forms->data), 'PRIVATE LEAD MESSAGE')===false);
$invalid=Fresh_Core_CRM::contacts(new WP_REST_Request(['kind'=>'users','after_id'=>0,'limit'=>1000]));
assert($invalid instanceof WP_Error);
echo "CRM read bridge smoke OK\n";
