<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-simple-mode.php';

$t = 'PTK_Simple_Mode';

// The meta and option names.
ptk_test_ok( 'ptk_simple_mode' === $t::USER_META, 'user meta key is ptk_simple_mode' );
ptk_test_ok( 'ptk_simple_mode_roles' === $t::ROLES_OPTION, 'site option is ptk_simple_mode_roles' );

// Everyone but administrators, by default.
ptk_test_ok( array( 'editor', 'author', 'contributor', 'subscriber' ) === $t::default_roles(), 'default_roles() is everyone but administrator' );
ptk_test_ok( ! in_array( 'administrator', $t::default_roles(), true ), 'administrator is never a default-on role' );

// on_for(): the person's own choice wins.
ptk_test_ok( false === $t::on_for( '0', array( 'subscriber' ), $t::default_roles() ), 'an explicit 0 beats a default-on role' );
ptk_test_ok( true === $t::on_for( '1', array( 'administrator' ), $t::default_roles() ), 'an explicit 1 beats a default-off role' );

// on_for(): absent meta falls back to the role default.
ptk_test_ok( false === $t::on_for( '', array( 'administrator' ), $t::default_roles() ), 'an admin with no meta is off' );
ptk_test_ok( true === $t::on_for( '', array( 'subscriber' ), $t::default_roles() ), 'a volunteer with no meta is on' );
ptk_test_ok( false === $t::on_for( '', array( 'some_unknown_role' ), $t::default_roles() ), 'an unknown role is ignored, so it is off' );
ptk_test_ok( true === $t::on_for( '', array( 'some_unknown_role', 'editor' ), $t::default_roles() ), 'one known default-on role among unknown ones is still on' );
ptk_test_ok( false === $t::on_for( '', array(), $t::default_roles() ), 'no roles at all is off' );

// A site can choose its own default list instead of the built-in one.
ptk_test_ok( true === $t::on_for( '', array( 'administrator' ), array( 'administrator' ) ), 'a site-chosen default list can include administrator' );

// sanitize_roles(): only real role slugs survive.
$all_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
ptk_test_ok( array( 'editor', 'author' ) === $t::sanitize_roles( array( 'editor', 'author' ), $all_roles ), 'real roles pass through' );
ptk_test_ok( array( 'editor' ) === $t::sanitize_roles( array( 'editor', 'made-up-role' ), $all_roles ), 'a made-up role is dropped' );
ptk_test_ok( array( 'administrator' ) === $t::sanitize_roles( array( 'administrator' ), $all_roles ), 'administrator may be included if a site chooses' );
ptk_test_ok( array() === $t::sanitize_roles( array(), $all_roles ), 'nothing submitted keeps nothing' );
ptk_test_ok( array() === $t::sanitize_roles( 'not-an-array', $all_roles ), 'a non-array submission is treated as nothing' );
ptk_test_ok( array( 'editor' ) === $t::sanitize_roles( array( 'editor', 'editor' ), $all_roles ), 'a duplicate does not appear twice' );

// too_small_for_hub(): people who lack edit_posts never land in the admin.
ptk_test_ok( true === $t::too_small_for_hub( array() ), 'no caps at all is too small for the Hub' );
ptk_test_ok( true === $t::too_small_for_hub( array( 'read' => true ) ), 'read-only (a plain subscriber) is too small for the Hub' );
ptk_test_ok( true === $t::too_small_for_hub( array( 'edit_posts' => false ) ), 'edit_posts explicitly false is too small for the Hub' );
ptk_test_ok( false === $t::too_small_for_hub( array( 'edit_posts' => true ) ), 'edit_posts true is not too small for the Hub' );

// active(): nothing is on when the new look is off, whatever else is true.
ptk_test_ok( false === $t::active( false, '1', array( 'administrator' ), array( 'administrator' ) ), 'the new look off beats an explicit 1' );
ptk_test_ok( false === $t::active( false, '', array( 'subscriber' ), $t::default_roles() ), 'the new look off beats a default-on role' );
ptk_test_ok( true === $t::active( true, '1', array(), array() ), 'the new look on plus an explicit 1 is on' );
ptk_test_ok( true === $t::active( true, '', array( 'subscriber' ), $t::default_roles() ), 'the new look on plus a default-on role is on' );
ptk_test_ok( false === $t::active( true, '', array( 'administrator' ), $t::default_roles() ), 'the new look on but an admin with no meta is off' );

// keep_menu_slug(): a plain allow-list check, testable without WordPress.
ptk_test_ok( true === $t::keep_menu_slug( 'profile.php', array( 'edit.php?post_type=pta_knowledge', 'profile.php' ) ), 'profile.php survives when it is in the keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'plugins.php', array( 'edit.php?post_type=pta_knowledge', 'profile.php' ) ), 'plugins.php does not survive when it is not in the keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'anything', array() ), 'nothing survives an empty keep list' );
ptk_test_ok( false === $t::keep_menu_slug( 'anything', null ), 'a non-array keep list is treated as empty' );

// The top-level keep list: the Hub's own menu, plus Profile, nothing else.
ptk_test_ok( array( 'edit.php?post_type=pta_knowledge', 'ptk-show-wordpress' ) === $t::top_level_keep_slugs(), 'top level keeps only the Hub menu and the way out' );
// WordPress's own profile screen cannot be trimmed honestly -- two of its
// worst parts have no hook -- so it leaves the menu. "Howdy, ..." in the
// top bar still reaches it.
ptk_test_ok( ! in_array( 'profile.php', $t::top_level_keep_slugs(), true ), 'Profile is not in the trimmed menu' );
ptk_test_ok( in_array( 'my-account', $t::admin_bar_keep_ids(), true ), 'but the account menu in the top bar stays, so nobody is stranded' );
// my-account sits inside the "top-secondary" group, whose own parent is
// root -- drop the group and Edit Profile and Log Out go with it.
ptk_test_ok( in_array( 'top-secondary', $t::admin_bar_keep_ids(), true ), 'the group the account menu lives in stays too, or keeping my-account means nothing' );

// The door between the two views is offered in BOTH directions: someone who
// showed all of WordPress and is standing in Media needs a way home that
// they can see, not just a quiet link on a screen they left.
ptk_test_ok( true === $t::exit_offered( true, false ), 'the way out is offered where the new look is on' );
ptk_test_ok( false === $t::exit_offered( false, false ), 'and never where the new look is off -- there is no simple view to return to' );
ptk_test_ok( false === $t::exit_offered( true, true ), 'and not to someone the Hub is too small for' );

// The Hub-task submenu keep list: task screens, not once-in-a-while admin screens.
$hub_tasks = $t::hub_task_submenu_slugs();
foreach ( array( 'ptk-welcome', 'ptk-newsletter-builder', 'ptk-share-settings', 'ptk-content-wizard', 'edit.php?post_type=pta_knowledge', 'edit.php?post_type=pta_newsletter' ) as $task_slug ) {
    ptk_test_ok( in_array( $task_slug, $hub_tasks, true ), "hub_task_submenu_slugs() keeps $task_slug" );
}
// Newsletter settings holds the switch that turns the whole new look off,
// so it stays reachable even though it's otherwise a once-in-a-while screen.
ptk_test_ok( in_array( 'ptk-share-settings', $hub_tasks, true ), 'hub_task_submenu_slugs() keeps Newsletter settings -- it holds the new-look switch' );
foreach ( array( 'ptk-settings', 'ptk-search-analytics', 'ptk-content-importer', 'ptk-network-sync', 'ptk-school-colors', 'ptk-vendor-approvals', 'ptk-asked-for' ) as $admin_slug ) {
    ptk_test_ok( ! in_array( $admin_slug, $hub_tasks, true ), "hub_task_submenu_slugs() drops the once-in-a-while admin screen $admin_slug" );
}

// With the new look on, "What you've written" replaces the raw list in the
// kept menu -- the raw list stays registered (WordPress's own screen), just
// not in the trimmed Simple mode menu.
$hub_tasks_written = $t::hub_task_submenu_slugs( true );
ptk_test_ok( in_array( 'ptk-written', $hub_tasks_written, true ), 'hub_task_submenu_slugs( true ) keeps ptk-written' );
// "Words you've explained" is a home for content, so it earns a menu entry
// beside it -- but only in the new look, where that screen exists at all.
ptk_test_ok( in_array( 'ptk-words', $hub_tasks_written, true ), 'hub_task_submenu_slugs( true ) keeps ptk-words' );
ptk_test_ok( in_array( 'ptk-newsletters', $hub_tasks_written, true ), 'hub_task_submenu_slugs( true ) keeps ptk-newsletters' );
ptk_test_ok( ! in_array( 'edit.php?post_type=pta_newsletter', $hub_tasks_written, true ), "hub_task_submenu_slugs( true ) drops WordPress's own newsletter list" );
ptk_test_ok( ! in_array( 'ptk-words', $hub_tasks, true ), 'ptk-words is not in the old-look menu, where the screen does not exist' );
ptk_test_ok( ! in_array( 'edit.php?post_type=pta_knowledge', $hub_tasks_written, true ), 'hub_task_submenu_slugs( true ) drops the raw All Entries list' );
foreach ( array( 'ptk-welcome', 'ptk-newsletter-builder', 'ptk-share-settings', 'ptk-content-wizard' ) as $task_slug ) {
    ptk_test_ok( in_array( $task_slug, $hub_tasks_written, true ), "hub_task_submenu_slugs( true ) still keeps $task_slug" );
}

// The admin-bar keep list.
$bar_keep = $t::admin_bar_keep_ids();
foreach ( array( 'site-name', 'my-account', 'top-secondary', 'ptk-simple-mode' ) as $bar_id ) {
    ptk_test_ok( in_array( $bar_id, $bar_keep, true ), "admin_bar_keep_ids() keeps $bar_id" );
}
// "My Sites" opens WordPress's own list of all eleven PTAs, and its
// Dashboard links land a volunteer in another school's admin.
foreach ( array( 'new-content', 'comments', 'updates', 'wp-logo', 'my-sites' ) as $bar_id ) {
    ptk_test_ok( ! in_array( $bar_id, $bar_keep, true ), "admin_bar_keep_ids() drops $bar_id" );
}

// redirect_target(): only a same-site target survives; anything else falls back.
ptk_test_ok( '/wp-admin/edit.php' === $t::redirect_target( '/wp-admin/edit.php', 'https://example.org', '/fallback' ), 'a bare same-site path survives' );
ptk_test_ok( 'https://example.org/wp-admin/' === $t::redirect_target( 'https://example.org/wp-admin/', 'https://example.org', '/fallback' ), 'a full url on the same host survives' );
ptk_test_ok( '/fallback' === $t::redirect_target( 'https://evil.example/steal', 'https://example.org', '/fallback' ), 'a different host falls back' );
ptk_test_ok( '/fallback' === $t::redirect_target( '//evil.example/steal', 'https://example.org', '/fallback' ), 'a scheme-relative //host trick falls back' );
ptk_test_ok( '/fallback' === $t::redirect_target( '\\\\evil.example/steal', 'https://example.org', '/fallback' ), 'a backslash trick falls back' );
ptk_test_ok( '/fallback' === $t::redirect_target( '', 'https://example.org', '/fallback' ), 'empty input falls back' );
ptk_test_ok( '/fallback' === $t::redirect_target( null, 'https://example.org', '/fallback' ), 'non-string input falls back' );

// login_redirect_target(): Simple mode off leaves the default alone; on, edit_posts goes home, anyone smaller goes public.
ptk_test_ok( 'wp-admin/' === $t::login_redirect_target( 'wp-admin/', false, true, 'HUB_HOME', 'PUBLIC_HUB' ), 'Simple mode off leaves the default redirect alone' );
ptk_test_ok( 'HUB_HOME' === $t::login_redirect_target( 'wp-admin/', true, false, 'HUB_HOME', 'PUBLIC_HUB' ), 'Simple mode on, edit_posts, goes to the Hub home' );
ptk_test_ok( 'PUBLIC_HUB' === $t::login_redirect_target( 'wp-admin/', true, true, 'HUB_HOME', 'PUBLIC_HUB' ), 'Simple mode on, too small for the Hub, goes to the PUBLIC Hub page' );

// should_leave_dashboard(): only index.php, only Simple mode on, never ajax/cron/network-admin.
ptk_test_ok( true === $t::should_leave_dashboard( 'index.php', true, false, false, false ), 'the plain dashboard, Simple mode on, leaves' );
ptk_test_ok( false === $t::should_leave_dashboard( 'index.php', false, false, false, false ), 'Simple mode off never leaves' );
ptk_test_ok( false === $t::should_leave_dashboard( 'edit.php', true, false, false, false ), 'any other screen never leaves' );
ptk_test_ok( false === $t::should_leave_dashboard( 'index.php', true, true, false, false ), 'an AJAX request never leaves' );
ptk_test_ok( false === $t::should_leave_dashboard( 'index.php', true, false, true, false ), 'a cron request never leaves' );
ptk_test_ok( false === $t::should_leave_dashboard( 'index.php', true, false, false, true ), 'a network-admin request never leaves' );

// should_clear_notices(): only a Hub screen, only with Simple mode on.
ptk_test_ok( true === $t::should_clear_notices( true, true ), 'a Hub screen with Simple mode on clears notices' );
ptk_test_ok( false === $t::should_clear_notices( false, true ), 'Simple mode on but not a Hub screen does not clear notices' );
ptk_test_ok( false === $t::should_clear_notices( true, false ), 'a Hub screen but Simple mode off does not clear notices' );
ptk_test_ok( false === $t::should_clear_notices( false, false ), 'neither a Hub screen nor Simple mode on does not clear notices' );

ptk_test_done();
