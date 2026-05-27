<?php
/**
 * Content Importer — one-time bulk import of knowledge base entries.
 *
 * Creates draft posts from pre-written content (e.g., from a PDF or
 * planning document). Each entry is created as a draft so an admin
 * can review and publish when ready.
 *
 * Triggered from: PTA Knowledge > Import Starter Content (admin menu).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Content_Importer {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_import_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_import' ) );
    }

    /**
     * Add a submenu item for the importer — hidden once content has been
     * imported unless re-enabled in Settings.
     */
    public static function add_import_page() {
        // Always visible. The page itself shows a clear warning when a previous
        // import is detected, which is friendlier than hiding the option entirely.
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Import Starter Content',
            'Import Starter Content',
            'manage_options',
            'ptk-content-importer',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Render the import page.
     */
    public static function render_page() {
        $already_imported = get_option( 'ptk_starter_content_imported', false );
        ?>
        <div class="wrap">
            <h1>Import Starter Content</h1>

            <?php if ( isset( $_GET['ptk_imported'] ) ) : ?>
                <div class="notice notice-success">
                    <p><strong>Done!</strong> <?php echo absint( $_GET['ptk_imported'] ); ?> entries were created as drafts.
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=pta_knowledge&post_status=draft' ) ); ?>">Review drafts &rarr;</a></p>
                </div>
            <?php endif; ?>

            <?php if ( $already_imported && ! isset( $_GET['ptk_imported'] ) ) : ?>
                <div class="notice notice-warning" style="border-left-width:6px;padding:12px 16px;font-size:14px;">
                    <p style="margin:0;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-warning" style="color:#b45309;"></span>
                        <strong>Starter content has already been imported.</strong> Running it again will create duplicate entries.
                    </p>
                </div>
            <?php endif; ?>

            <div style="max-width:700px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin-top:20px;">
                <h2 style="margin-top:0;">PTA Technology Starter Pack</h2>
                <p>This will create <strong>20 draft entries</strong> covering the basics of PTA technology — written in plain English for new volunteers. Topics include:</p>
                <ul style="margin-left:20px;">
                    <li>📖 <strong>6 Glossary Terms</strong> — What is Givebacks? WordPress Multisite? Google Workspace? etc.</li>
                    <li>📋 <strong>6 How-To Guides</strong> — Log into your website, edit your homepage, store files, etc.</li>
                    <li>✅ <strong>2 Checklists</strong> — New officer transition for Givebacks and Website</li>
                    <li>❓ <strong>5 FAQs</strong> — Common questions new volunteers ask</li>
                    <li>📁 <strong>1 Resource</strong> — Technology tools overview</li>
                </ul>
                <p>All entries are created as <strong>drafts</strong> — nothing is published until you review and approve each one.</p>

                <form method="post" action="">
                    <?php wp_nonce_field( 'ptk_import_starter', 'ptk_import_nonce' ); ?>
                    <button type="submit" name="ptk_do_import" value="1" class="button button-primary button-hero"
                            onclick="return confirm('This will create 20 draft entries. Continue?');">
                        Import Starter Content as Drafts
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the import action.
     */
    public static function handle_import() {
        if ( ! isset( $_POST['ptk_do_import'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['ptk_import_nonce'] ?? '', 'ptk_import_starter' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to do this.' );
        }

        $entries = self::get_starter_entries();
        $count = 0;

        foreach ( $entries as $entry ) {
            $post_id = wp_insert_post( array(
                'post_title'   => $entry['title'],
                'post_content' => $entry['content'],
                'post_excerpt' => $entry['excerpt'] ?? '',
                'post_status'  => 'draft',
                'post_type'    => 'pta_knowledge',
            ), true );

            if ( is_wp_error( $post_id ) ) {
                continue;
            }

            // Set category.
            $term = get_term_by( 'slug', $entry['category'], 'knowledge_category' );
            if ( $term ) {
                wp_set_post_terms( $post_id, array( $term->term_id ), 'knowledge_category' );
            }

            // Set tags.
            if ( ! empty( $entry['tags'] ) ) {
                wp_set_post_tags( $post_id, $entry['tags'] );
            }

            // Set meta fields.
            if ( ! empty( $entry['meta'] ) ) {
                foreach ( $entry['meta'] as $key => $value ) {
                    update_post_meta( $post_id, $key, $value );
                }
            }

            $count++;
        }

        update_option( 'ptk_starter_content_imported', true );

        wp_safe_redirect( add_query_arg(
            array(
                'post_type'    => 'pta_knowledge',
                'page'         => 'ptk-content-importer',
                'ptk_imported' => $count,
            ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /**
     * All starter entries — plain-English content created from the
     * PTA Technology Infrastructure document.
     */
    private static function get_starter_entries() {
        return array(

            // ─── GLOSSARY TERMS ───────────────────────────────

            array(
                'title'    => 'Givebacks',
                'category' => 'glossary',
                'tags'     => array( 'givebacks', 'membership', 'store', 'NJ PTA', 'compliance' ),
                'excerpt'  => 'Givebacks is the online system your PTA uses to manage memberships, run an online store, send newsletters, and stay in compliance with NJ PTA requirements.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'Givebacks is the online system your PTA uses to manage memberships, run an online store, send newsletters, and stay in compliance with NJ PTA requirements. Think of it as PTA headquarters online.' ),
                    self::heading( 'More Details' ),
                    self::para( 'Givebacks does several things for your PTA:' ),
                    self::list_block( array(
                        '<strong>Membership:</strong> People join and pay dues through your Givebacks store. It keeps track of who\'s a member.',
                        '<strong>Online Store:</strong> You can sell things like spirit wear (school-branded clothing) through a built-in store.',
                        '<strong>Newsletters:</strong> You can create and send email newsletters to your members.',
                        '<strong>Compliance:</strong> NJ PTA requires every PTA to use Givebacks. It stores your tax forms, officer contacts, bylaws, and other official documents.',
                    ) ),
                    self::para( 'Payments go through a service called Stripe — each PTA sets up their own Stripe account inside Givebacks.' ),
                    self::heading( 'Example' ),
                    self::para_em( 'When a parent wants to join the PTA, they go to a link on your website that takes them to your Givebacks store. They pay their dues there, and Givebacks automatically records them as a member.' ),
                ) ),
            ),

            array(
                'title'    => 'WordPress Multisite',
                'category' => 'glossary',
                'tags'     => array( 'wordpress', 'website', 'multisite' ),
                'excerpt'  => 'WordPress Multisite is a system that lets all 11 Montclair PTA websites share one platform. This keeps them looking consistent and makes them easier to manage.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'WordPress Multisite is a system that lets all 11 Montclair PTA websites share one platform. This keeps them looking consistent and makes them easier to manage.' ),
                    self::heading( 'More Details' ),
                    self::para( 'Instead of each PTA having a completely separate website, all the PTA sites live under one roof. This means:' ),
                    self::list_block( array(
                        'All sites share the same header and footer, so parents with kids in multiple schools only need to learn one layout.',
                        'Calendars can be shared across PTAs — the main PTAC site shows events from all schools.',
                        'When volunteers move from one school\'s PTA to another (as kids change schools), the tools work the same way.',
                        'WordPress is the most popular website system in the world, so there are lots of resources and help available.',
                    ) ),
                    self::heading( 'Important' ),
                    self::para( 'The PTA website is completely separate from the school\'s website (which is run by the school district). They may link to each other, but they are managed by different people.' ),
                ) ),
            ),

            array(
                'title'    => 'Google Workspace',
                'category' => 'glossary',
                'tags'     => array( 'google', 'email', 'files', 'shared drive', 'workspace' ),
                'excerpt'  => 'Google Workspace is a set of online tools (email, file storage, documents, spreadsheets) provided for PTA business. It\'s separate from any school-provided Google accounts.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'Google Workspace is a set of online tools (email, file storage, documents, spreadsheets) provided for PTA business. It\'s separate from any school-provided Google accounts.' ),
                    self::heading( 'More Details' ),
                    self::para( 'Your PTA\'s Google Workspace gives you:' ),
                    self::list_block( array(
                        '<strong>Email:</strong> An official PTA email address (like president@yourpta.org)',
                        '<strong>Shared Drives:</strong> A shared folder where all PTA files live — accessible to anyone who needs them',
                        '<strong>Google Docs, Sheets, Forms:</strong> Tools for creating documents, spreadsheets, and surveys',
                        '<strong>Google Calendar:</strong> For scheduling PTA events and meetings',
                    ) ),
                    self::heading( 'Important' ),
                    self::para( 'This is NOT the same as your personal Gmail or any school-related Google account (like Google Classroom). Keep PTA business on the PTA workspace to maintain privacy and make it easy for the next person in your role to find everything.' ),
                ) ),
            ),

            array(
                'title'    => 'POSSE',
                'category' => 'glossary',
                'tags'     => array( 'POSSE', 'publishing', 'social media', 'website', 'best practice' ),
                'excerpt'  => 'POSSE stands for "Publish on your Own Site, Syndicate Elsewhere." It means: put your content on the PTA website first, then share it to email, social media, etc.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'POSSE stands for "Publish on your Own Site, Syndicate Elsewhere." In simple terms: put your content on the PTA website first, then share it to email, social media, and other places.' ),
                    self::heading( 'Why It Matters' ),
                    self::list_block( array(
                        'Your website becomes the single source of truth — one place where everything lives.',
                        'You can update information in one spot instead of fixing it in 5 different places.',
                        'People can always find the latest version on the website, even if they missed the email or social media post.',
                    ) ),
                    self::heading( 'Example' ),
                    self::para_em( 'Instead of writing a bake sale announcement in an email AND on Instagram AND on the website separately, write it once as a website post. Then share a link to that post in your email newsletter and on social media.' ),
                ) ),
            ),

            array(
                'title'    => 'Beaver Builder',
                'category' => 'glossary',
                'tags'     => array( 'beaver builder', 'page builder', 'website', 'editing' ),
                'excerpt'  => 'Beaver Builder is the drag-and-drop tool used to design and edit pages on the PTA website. You don\'t need to know any code to use it.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'Beaver Builder is the drag-and-drop tool used to design and edit pages on the PTA website. You don\'t need to know any code to use it.' ),
                    self::heading( 'More Details' ),
                    self::para( 'When you want to change how a page looks on the PTA website — move text around, add images, change the layout — you use Beaver Builder. It works by clicking on elements and dragging them where you want.' ),
                    self::para( 'You\'ll see it when you click "Page Builder" while editing any page on the PTA site.' ),
                    self::heading( 'Good to Know' ),
                    self::list_block( array(
                        'Beaver Builder does NOT have a good "undo" feature. If you make a mistake, it can be hard to go back.',
                        'Best practice: work on a copy of your page, then swap it in when you\'re happy with the changes.',
                        'The header and footer use Beaver Builder too, but they\'re edited separately through the "Themer" tool.',
                    ) ),
                ) ),
            ),

            array(
                'title'    => 'Stripe',
                'category' => 'glossary',
                'tags'     => array( 'stripe', 'payments', 'money', 'givebacks', 'dues' ),
                'excerpt'  => 'Stripe is the online payment system that processes credit card payments when people join the PTA or buy from the online store. Each PTA has its own Stripe account.',
                'content'  => self::blocks( array(
                    self::heading( 'What It Means' ),
                    self::bold_para( 'Stripe is the online payment system that processes credit card payments when people join the PTA or buy from the online store. Each PTA has its own Stripe account inside Givebacks.' ),
                    self::heading( 'More Details' ),
                    self::para( 'You don\'t need to interact with Stripe directly most of the time — it works behind the scenes inside Givebacks. When someone pays their PTA dues or buys spirit wear, Stripe handles the credit card processing and puts the money into your PTA\'s bank account.' ),
                    self::para( 'The Treasurer and President are usually the only ones who need to access Stripe settings.' ),
                ) ),
            ),

            // ─── HOW-TO GUIDES ────────────────────────────────

            array(
                'title'    => 'How to Log Into Your PTA Website',
                'category' => 'how-to-guide',
                'tags'     => array( 'login', 'website', 'wordpress', 'getting started' ),
                'excerpt'  => 'A quick guide to logging into your PTA website so you can start editing.',
                'meta'     => array( 'ptk_difficulty' => 'Easy', 'ptk_time_estimate' => '2 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'Logging into your PTA website takes about 30 seconds. Here\'s how.' ),
                    self::heading( 'Steps' ),
                    self::h3( 'Step 1' ),
                    self::para( 'Open your web browser and go to your PTA\'s website. For example: montclairpta.org' ),
                    self::h3( 'Step 2' ),
                    self::para( 'Add /pta-login to the end of your website address. For example: montclairpta.org/pta-login' ),
                    self::h3( 'Step 3' ),
                    self::para( 'Enter your username and password on the login screen that appears.' ),
                    self::h3( 'Step 4' ),
                    self::para( 'After logging in, you\'ll see your PTA homepage with extra menus across the top. These menus are how you edit the site — they\'re only visible when you\'re logged in.' ),
                    self::heading( 'Tips & Notes' ),
                    self::list_block( array(
                        'Your website login is separate from your Givebacks login and your Google Workspace login. They might use the same password, but they\'re different accounts.',
                        'If you don\'t have a login yet, contact your PTA\'s webmaster or the technology committee.',
                        'Most PTA websites only need a few people to have login access: the Webmaster, Corresponding Secretary, and President.',
                    ) ),
                ) ),
            ),

            array(
                'title'    => 'How to Edit Your PTA Homepage',
                'category' => 'how-to-guide',
                'tags'     => array( 'homepage', 'website', 'editing', 'page builder' ),
                'excerpt'  => 'Learn how to make changes to your PTA website\'s homepage using the page builder.',
                'meta'     => array( 'ptk_difficulty' => 'Medium', 'ptk_time_estimate' => '15 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'Your homepage is the first thing visitors see. Here\'s how to update it.' ),
                    self::heading( 'Steps' ),
                    self::h3( 'Step 1' ),
                    self::para( 'Log into your PTA website (see "How to Log Into Your PTA Website").' ),
                    self::h3( 'Step 2' ),
                    self::para( 'Go to your homepage. You should see editing menus across the top of the page since you\'re logged in.' ),
                    self::h3( 'Step 3' ),
                    self::para( 'Click "Page Builder" in the top menu to open the visual editor. This is Beaver Builder — it lets you drag and drop elements on the page.' ),
                    self::h3( 'Step 4' ),
                    self::para( 'Click on any section of the page to edit its text, images, or layout. When you\'re done, click "Done" in the upper right and then "Publish" to save your changes.' ),
                    self::heading( 'Tips & Notes' ),
                    self::list_block( array(
                        'The homepage is set in Settings > Reading in the WordPress dashboard. Ask the tech committee if you\'re not sure which page is your homepage.',
                        'Pro tip: Make a copy of the page before editing. That way, if something goes wrong, you have a backup. Beaver Builder doesn\'t have a great undo feature.',
                        'The header and footer are edited separately — see "How to Edit Your Website Header or Footer."',
                    ) ),
                ) ),
            ),

            array(
                'title'    => 'How to Edit Your Website Header or Footer',
                'category' => 'how-to-guide',
                'tags'     => array( 'header', 'footer', 'website', 'themer', 'editing' ),
                'excerpt'  => 'The header and footer appear on every page. Here\'s how to edit them without breaking anything.',
                'meta'     => array( 'ptk_difficulty' => 'Medium', 'ptk_time_estimate' => '10 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'The header (top of every page) and footer (bottom of every page) are shared across your entire site. Editing them is slightly different from editing a regular page.' ),
                    self::heading( 'Steps' ),
                    self::h3( 'Step 1' ),
                    self::para( 'Log into your PTA website and go to the Dashboard (you can find it in the "My Sites" menu at the top left).' ),
                    self::h3( 'Step 2' ),
                    self::para( 'In the left sidebar, look for "Beaver Builder" and then click "Themer Layouts."' ),
                    self::h3( 'Step 3' ),
                    self::para( 'Find your PTA\'s header or footer in the list and click "Edit."' ),
                    self::h3( 'Step 4' ),
                    self::para( 'Click "Launch Page Builder" to open the visual editor. Make your changes, then click "Done" and "Publish."' ),
                    self::heading( 'Tips & Notes' ),
                    self::list_block( array(
                        'DO NOT modify the top navigation bar that appears across all PTA sites (the one with links to other schools). That\'s a shared element maintained by the tech committee.',
                        'You CAN change things like your school\'s logo, banner image, and contact info in the footer.',
                        'Menu links in the header are managed separately — see the section on Navigation Menus.',
                    ) ),
                ) ),
            ),

            array(
                'title'    => 'How to Set Up New Officers in Givebacks',
                'category' => 'how-to-guide',
                'tags'     => array( 'givebacks', 'officers', 'transition', 'new year', 'setup' ),
                'excerpt'  => 'Every July 1st, new PTA officers need to be entered into Givebacks. Here\'s how.',
                'meta'     => array( 'ptk_difficulty' => 'Easy', 'ptk_time_estimate' => '15 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'When new officers take over (usually July 1st), they need to be registered in Givebacks. This is important because Givebacks wipes out officer information every year.' ),
                    self::heading( 'Steps' ),
                    self::h3( 'Step 1' ),
                    self::para( 'Log into Givebacks and navigate to the officer management section.' ),
                    self::h3( 'Step 2' ),
                    self::para( 'Enter each new officer\'s name, role, and contact information. Use PTA email addresses (like president@yourpta.org) instead of personal email addresses — this is more secure and makes future transitions easier.' ),
                    self::h3( 'Step 3' ),
                    self::para( 'Important: Givebacks only allows ONE President and ONE Treasurer. If two people share the role, one must be listed as the official holder and the other given a different title (like Vice President).' ),
                    self::h3( 'Step 4' ),
                    self::para( 'Remove login access for people who are no longer involved. At minimum, keep access for the President, Treasurer, and one technology-focused person.' ),
                    self::h3( 'Step 5' ),
                    self::para( 'Double-check that the banking information on file is still correct.' ),
                    self::heading( 'Tips & Notes' ),
                    self::list_block( array(
                        'Givebacks has its own training videos and support. Look for the help section inside the platform.',
                        'If someone only needs limited access (like managing a single event), give them a restricted role — don\'t give everyone full admin rights.',
                    ) ),
                ) ),
            ),

            array(
                'title'    => 'How to Store and Share PTA Files the Right Way',
                'category' => 'how-to-guide',
                'tags'     => array( 'files', 'google drive', 'shared drive', 'storage', 'best practices' ),
                'excerpt'  => 'Where to save PTA files so the next person in your role can find them, and why you should share links instead of attachments.',
                'meta'     => array( 'ptk_difficulty' => 'Easy', 'ptk_time_estimate' => '5 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'How you store and share files matters. The right approach makes life easier for everyone — especially the person who takes over your role next year.' ),
                    self::heading( 'The Golden Rules' ),
                    self::list_block( array(
                        '<strong>Save files on the Shared Drive</strong> — not in "My Files." Shared Drives are accessible to anyone who needs them. "My Files" are private to you and harder for your successor to access.',
                        '<strong>Share links, not file attachments</strong> — When you email someone a file, they get a copy. Then there are two versions, and nobody knows which is up-to-date. Instead, share a link to the file on the Shared Drive.',
                        '<strong>Don\'t use email as file storage</strong> — It\'s tempting to keep everything in your inbox, but it\'s insecure and creates version confusion.',
                    ) ),
                    self::heading( 'How to Share a Link from Google Drive' ),
                    self::list_block( array(
                        'Find your file on the Shared Drive',
                        'Right-click it and select "Share" or "Get link"',
                        'Copy the link and paste it into your email or message',
                    ), true ),
                    self::heading( 'Tips & Notes' ),
                    self::para( 'Each PTA has its own Shared Drive on the PTAC Google Workspace. If you can\'t find yours, ask the technology committee. They can also help move files from personal accounts to the shared space.' ),
                ) ),
            ),

            array(
                'title'    => 'How to Get Technology Help',
                'category' => 'how-to-guide',
                'tags'     => array( 'help', 'support', 'technology committee', 'contact' ),
                'excerpt'  => 'Who to contact when you need help with the website, Givebacks, Google Workspace, or any PTA technology.',
                'meta'     => array( 'ptk_difficulty' => 'Easy', 'ptk_time_estimate' => '2 minutes' ),
                'content'  => self::blocks( array(
                    self::para( 'Stuck on something? Don\'t worry — there\'s a team of people here to help.' ),
                    self::heading( 'Steps' ),
                    self::h3( 'Step 1: Email the Technology Committee' ),
                    self::para( 'Send your question to: technologycommittee@montclairpta.org' ),
                    self::para( 'This goes to the whole tech committee, so someone will see it and respond. Emails are also saved so if committee members change, the history is preserved.' ),
                    self::h3( 'Step 2: Bring It Up at a Meeting' ),
                    self::para( 'The technology committee meets monthly. Check the "PTA Technology Committee" calendar for the next meeting date. You can bring questions, feature requests, or ideas.' ),
                    self::h3( 'For Givebacks Specifically' ),
                    self::para( 'Givebacks has its own support: training videos, how-to documents, and even an AI chatbot inside the platform. For Givebacks-specific questions, try their built-in help first.' ),
                    self::heading( 'Tips & Notes' ),
                    self::list_block( array(
                        'No question is too basic. The tech committee exists to help.',
                        'If you want a new feature on the website (like a new plugin), don\'t try to install it yourself — request it through the tech committee. Plugins need to be tested for compatibility with all 11 PTA sites.',
                    ) ),
                ) ),
            ),

            // ─── CHECKLISTS ───────────────────────────────────

            array(
                'title'    => 'New Officer Transition Checklist: Givebacks',
                'category' => 'checklist',
                'tags'     => array( 'transition', 'new officers', 'givebacks', 'july', 'onboarding' ),
                'excerpt'  => 'Everything new PTA officers need to do in Givebacks when they take over.',
                'content'  => self::blocks( array(
                    self::para( 'Use this checklist when new officers start their roles (usually July 1st). Givebacks resets officer data each year, so this needs to happen every time.' ),
                    self::heading( 'Checklist' ),
                    self::list_block( array(
                        '☐ Enter new officers\' names, roles, and contact info in Givebacks (use PTA email addresses, not personal ones)',
                        '☐ Make sure only ONE person is listed as President and ONE as Treasurer (even if roles are shared)',
                        '☐ Review and refresh all login credentials',
                        '☐ Remove access for people no longer in their roles',
                        '☐ Keep admin access for at minimum: President, Treasurer, and one tech-focused person',
                        '☐ Give limited access to other roles (event managers, volunteers) — only what they need',
                        '☐ Verify banking information is correct and up to date',
                        '☐ Delete any student information (Genesis data) from the previous year',
                        '☐ Review the PTA\'s Stripe account settings',
                        '☐ Make sure your website has a link to the Givebacks membership store',
                    ) ),
                    self::heading( 'Notes' ),
                    self::para( 'If you get stuck, Givebacks has built-in training videos and support. You can also email technologycommittee@montclairpta.org for help.' ),
                ) ),
            ),

            array(
                'title'    => 'New Officer Transition Checklist: Website',
                'category' => 'checklist',
                'tags'     => array( 'transition', 'new officers', 'website', 'wordpress', 'onboarding' ),
                'excerpt'  => 'Everything new PTA officers need to do to get up to speed on the PTA website.',
                'content'  => self::blocks( array(
                    self::para( 'When new officers take over, they need access to the PTA website and should know the basics of how it works.' ),
                    self::heading( 'Checklist' ),
                    self::list_block( array(
                        '☐ Get your WordPress login credentials from the outgoing webmaster or tech committee',
                        '☐ Log in successfully (add /pta-login to your PTA website address)',
                        '☐ Locate the admin menu bar at the top of the page',
                        '☐ Find and review your PTA\'s homepage',
                        '☐ Learn how to open the Page Builder (Beaver Builder) to edit pages',
                        '☐ Review upcoming events on the calendar',
                        '☐ Check that committee and contact information is current',
                        '☐ Remove access for people no longer in their roles',
                        '☐ Know how to create a new Post (for news and announcements)',
                        '☐ Know who to contact for help (technologycommittee@montclairpta.org)',
                    ) ),
                    self::heading( 'Notes' ),
                    self::para( 'Don\'t be intimidated by the website! Most of what you\'ll need to do is straightforward — updating text, adding events, and creating posts. The tech committee is happy to walk you through anything.' ),
                ) ),
            ),

            // ─── FAQs ────────────────────────────────────────

            array(
                'title'    => 'Can I Install Plugins on My PTA Website?',
                'category' => 'faq',
                'tags'     => array( 'plugins', 'website', 'security', 'restrictions' ),
                'excerpt'  => 'No. Plugins are disabled on individual PTA sites because all 11 sites share one system. A bad plugin could break everyone\'s site. Request new features through the tech committee instead.',
                'content'  => self::blocks( array(
                    self::heading( 'Quick Answer' ),
                    self::bold_para( 'No. Plugins are disabled on individual PTA sites because all 11 sites share one system. A bad plugin could break everyone\'s site. Request new features through the tech committee instead.' ),
                    self::heading( 'Details' ),
                    self::para( 'Because all PTA websites run on a shared system (called a "multisite"), installing a plugin on one site could affect all the others. Not all plugins are tested to work safely in this setup.' ),
                    self::para( 'If you need a feature that isn\'t available, here\'s what to do:' ),
                    self::list_block( array(
                        'Email technologycommittee@montclairpta.org with your request',
                        'Describe what you\'re trying to accomplish (not just the plugin name)',
                        'The tech committee will evaluate it and install it if it\'s safe for the multisite',
                    ), true ),
                ) ),
                'meta'     => array( 'ptk_last_reviewed' => date( 'Y-m-d' ) ),
            ),

            array(
                'title'    => 'What\'s the Difference Between the PTA Website and the School Website?',
                'category' => 'faq',
                'tags'     => array( 'school', 'district', 'website', 'PTA vs school' ),
                'excerpt'  => 'They\'re completely separate. The school district runs the school website. The PTA runs the PTA website. They may link to each other, but different people manage them.',
                'content'  => self::blocks( array(
                    self::heading( 'Quick Answer' ),
                    self::bold_para( 'They\'re completely separate. The school district runs the school website. The PTA runs the PTA website. They may link to each other, but different people manage them.' ),
                    self::heading( 'Details' ),
                    self::para( 'It\'s easy to confuse the two, but they serve different purposes:' ),
                    self::list_block( array(
                        '<strong>School website</strong> (managed by the district): Class schedules, teacher info, school policies, lunch menus, Genesis access',
                        '<strong>PTA website</strong> (managed by PTA volunteers): PTA events, fundraisers, membership, volunteer opportunities, PTA news',
                    ) ),
                    self::para( 'While the PTA and school work together, they are legally separate organizations. This separation is important and must be maintained.' ),
                    self::para( 'One practical difference: PTA events added to the PTA website calendar do NOT automatically appear on the school district\'s calendar. If you want an event on both, you need to add it to the school calendar separately.' ),
                ) ),
                'meta'     => array( 'ptk_last_reviewed' => date( 'Y-m-d' ) ),
            ),

            array(
                'title'    => 'Can I Undo Changes on the Website?',
                'category' => 'faq',
                'tags'     => array( 'undo', 'mistakes', 'website', 'page builder', 'backup' ),
                'excerpt'  => 'It\'s limited. The page builder (Beaver Builder) doesn\'t have a great undo feature. Best practice: always make changes on a copy of the page first, then swap it in once you\'re happy.',
                'content'  => self::blocks( array(
                    self::heading( 'Quick Answer' ),
                    self::bold_para( 'It\'s limited. The page builder (Beaver Builder) doesn\'t have a great undo feature. Best practice: always make changes on a copy of the page first, then swap it in once you\'re happy.' ),
                    self::heading( 'Details' ),
                    self::para( 'Regular WordPress has a revision history feature, but the page builder doesn\'t support it well. That means:' ),
                    self::list_block( array(
                        '<strong>For pages:</strong> Make a copy of the page first. Edit the copy. Once it looks right, replace the original with the copy.',
                        '<strong>For posts:</strong> Posts are simpler and usually typed up elsewhere first. You can use undo buttons while editing, and you can unpublish a post immediately if needed.',
                        '<strong>For disasters:</strong> There are full site backups. If something goes seriously wrong, contact the technology committee and they can restore from a backup.',
                    ) ),
                ) ),
                'meta'     => array( 'ptk_last_reviewed' => date( 'Y-m-d' ) ),
            ),

            array(
                'title'    => 'Where Should PTA Calendar Events Go?',
                'category' => 'faq',
                'tags'     => array( 'calendar', 'events', 'school district', 'scheduling' ),
                'excerpt'  => 'Add events to your PTA website calendar. They\'ll show up on the main PTAC site too. Note: they don\'t automatically appear on the school district\'s calendar — that\'s a separate system.',
                'content'  => self::blocks( array(
                    self::heading( 'Quick Answer' ),
                    self::bold_para( 'Add events to your PTA website calendar. They\'ll show up on the main PTAC site too. But they won\'t automatically appear on the school district\'s calendar — that\'s a separate system.' ),
                    self::heading( 'Details' ),
                    self::para( 'Here\'s how the calendars work:' ),
                    self::list_block( array(
                        'Events you add to your PTA website calendar are automatically shared with the main PTAC website, which shows all PTA events across all schools.',
                        'The school district has its own separate calendar system. PTA events don\'t automatically show up there.',
                        'School district calendar events CAN be displayed on your PTA website, but it\'s a one-way connection — PTA events can\'t go the other direction automatically.',
                        'If you want your event on the school district calendar, you need to enter it there separately.',
                    ) ),
                ) ),
                'meta'     => array( 'ptk_last_reviewed' => date( 'Y-m-d' ) ),
            ),

            array(
                'title'    => 'Where Should I Save PTA Documents?',
                'category' => 'faq',
                'tags'     => array( 'files', 'shared drive', 'my files', 'google drive', 'storage' ),
                'excerpt'  => 'Always save PTA files on the Shared Drive in Google Workspace — never in "My Files." Shared Drives are accessible to everyone who needs them and make transitions to new officers seamless.',
                'content'  => self::blocks( array(
                    self::heading( 'Quick Answer' ),
                    self::bold_para( 'Always save PTA files on the Shared Drive in Google Workspace — never in "My Files." Shared Drives are accessible to everyone who needs them and make transitions to new officers seamless.' ),
                    self::heading( 'Details' ),
                    self::para( '"My Files" in Google Drive are private to you. When you leave your role, the next person can\'t easily access them. The Shared Drive belongs to the PTA, not to any individual.' ),
                    self::para( 'Also remember: share links to files, not the files themselves. Emailing file attachments creates multiple versions and nobody knows which one is current.' ),
                ) ),
                'meta'     => array( 'ptk_last_reviewed' => date( 'Y-m-d' ) ),
            ),

            // ─── RESOURCES ───────────────────────────────────

            array(
                'title'    => 'PTA Technology Tools Overview',
                'category' => 'resource',
                'tags'     => array( 'tools', 'overview', 'reference', 'canva', 'zoom', 'zelle', 'signup genius' ),
                'excerpt'  => 'A quick reference of all the technology tools used by the Montclair PTAs and what each one does.',
                'meta'     => array( 'ptk_file_type' => 'Other' ),
                'content'  => self::blocks( array(
                    self::heading( 'Description' ),
                    self::para( 'This is a quick reference for all the technology tools used across the Montclair PTAs. Bookmark this page for when you encounter a tool you\'re not familiar with.' ),
                    self::heading( 'PTA-Managed Platforms' ),
                    self::list_block( array(
                        '<strong>WordPress (PTA Website):</strong> Where all PTA content lives — events, news, resources. Edited with Beaver Builder.',
                        '<strong>Givebacks:</strong> Membership management, online store, newsletters, and NJ PTA compliance.',
                        '<strong>Google Workspace:</strong> Email, file storage (Shared Drives), Google Docs, Sheets, Forms, and Calendar.',
                    ) ),
                    self::heading( 'Other Tools You Might See' ),
                    self::list_block( array(
                        '<strong>Canva:</strong> Free design tool for creating flyers, posters, social media graphics, and newsletters.',
                        '<strong>Zoom / Google Meet:</strong> Video calls for virtual PTA meetings or events.',
                        '<strong>Zelle / PayPal / Apple Pay:</strong> Ways to collect money outside of Givebacks (e.g., for quick reimbursements).',
                        '<strong>Cheddar Up / Eventbrite / SignUp Genius:</strong> Event management and sign-up tools.',
                        '<strong>MailChimp:</strong> Some PTAs use this for email newsletters (Givebacks also has built-in newsletters).',
                        '<strong>Tally / JotForm / Google Forms:</strong> Online forms for surveys, RSVPs, and volunteer sign-ups.',
                        '<strong>WhatsApp / SMS:</strong> Quick real-time chat between volunteers.',
                        '<strong>Instagram / Facebook:</strong> Social media outreach (remember POSSE: post on the website first!).',
                    ) ),
                    self::heading( 'How to Use' ),
                    self::para( 'You don\'t need to learn all of these at once. Start with the three main platforms (Website, Givebacks, Google Workspace) and pick up the others as needed for specific events or projects.' ),
                ) ),
            ),
        );
    }

    // ─── Block Helper Methods ─────────────────────────────

    private static function blocks( $parts ) {
        return implode( "\n\n", $parts );
    }

    private static function heading( $text ) {
        return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->";
    }

    private static function h3( $text ) {
        return "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $text ) . "</h3>\n<!-- /wp:heading -->";
    }

    private static function para( $text ) {
        return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
    }

    private static function bold_para( $text ) {
        return "<!-- wp:paragraph {\"style\":{\"typography\":{\"fontWeight\":\"600\"}}} -->\n<p style=\"font-weight:600\">" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
    }

    private static function para_em( $text ) {
        return "<!-- wp:paragraph -->\n<p><em>" . esc_html( $text ) . "</em></p>\n<!-- /wp:paragraph -->";
    }

    private static function list_block( $items, $ordered = false ) {
        $tag = $ordered ? 'ol' : 'ul';
        $attr = $ordered ? ' {"ordered":true}' : '';
        $html = "<!-- wp:list$attr -->\n<$tag class=\"wp-block-list\">\n";
        foreach ( $items as $item ) {
            $html .= "<li>$item</li>\n";
        }
        $html .= "</$tag>\n<!-- /wp:list -->";
        return $html;
    }
}
