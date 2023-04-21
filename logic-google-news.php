<?php 
/*
* Plugin Name: Logic - Google News Sitemap
* Description: XML Sitemap for Google News
* Author: Logic Design & Consultancy Ltd
* Version: 1.0.1
*/

class Logic_Google_News
{
	// Location of xml cache
	public $file = ABSPATH . 'wp-content/uploads/logic-google-news-sitemap.xml';

	public function __construct()
	{
		// Rewrites, and cron setup
		add_action('init', [$this, 'init']);

		// Prevent trailing slash redirects
		add_action('redirect_canonical', [$this, 'redirect_canonical']);

		// XML Output
		add_action('pre_get_posts', [$this, 'pre_get_posts'], 1);

		// Admin menu link
		add_action('admin_menu', [$this, 'admin_menu']);

		// Admin menu view
		add_action('admin_init', [$this, 'admin_init']);

		// Generate XML file
		add_action('logic_build_xml', [$this, 'build_xml']);

		// Generate XML on hourly cron-schedule
		add_action('logic_rebuild_google_news_xml', [$this, 'build_xml_hourly']);
	}


	/**
	 * INIT
	 */
	public function init()
	{
		global $wp;

		// ADD REWRITE TO ENABLE Logic_google_news.xml
		$wp->add_query_var('logic-google-news-sitemap');
		add_rewrite_rule( 'logic-google-news\.xml$', 'index.php?logic-google-news-sitemap=1', 'top' );

		// ADD SCHEDULED TASK TO REBUILD XML
		if ( ! wp_next_scheduled('logic_rebuild_google_news_xml') ) {
			wp_schedule_event(time(), 'hourly', 'logic_rebuild_google_news_xml');
		}
	}


	/**
	 * REDIRECT_CANONICAL
	 * @param  string $redirect
	 * @return [string|bool]
	 */
	public function redirect_canonical($redirect)
	{
		if ( get_query_var( 'logic-google-news-sitemap' ) ) {
			return false;
		}

		return $redirect;
	}


	/**
	 * PRE_GET_POSTS
	 * @param  [object] $query
	 */
	public function pre_get_posts($query)
	{
		if ( ! $query->is_main_query() ) {
			return;
		}

		$logic_google_news_sitemap = get_query_var( 'logic-google-news-sitemap' );

		if ( ! empty($logic_google_news_sitemap) ) {
			$this->render_xml();
		}
	}


	/**
	 * RENDER_XML
	 */
	public function render_xml()
	{
		// IF FILE EXISTS, OUTPUT
		if ( file_exists($this->file) ) {
			http_response_code(200);
			header('Pragma: public');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Content-Type: text/xml');

			echo file_get_contents($this->file);
			exit;
		}


		// ELSE SHOW 404
		else {
			http_response_code(404);
			echo 'Sitemap contains no articles.';
			exit;
		}
	}


	/**
	 * BUILD_XML
	 */
	public function build_xml()
	{
		file_put_contents($this->file, '');

		// ENSURE FILE EXISTS
		if ( ! file_exists($this->file) ) {
			die('XML sitemap could not be created');
		}

		// GET NEWS
		// https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
		$news = new wp_query([
			'post_type' 		=> 'post',
			'post_status'		=> ['publish'],
			'posts_per_page' 	=> -1,
			'date_query' 		=> [
				[
					'after' 	=> '2 days ago',
					'inclusive' => true
				]
			]
		]);

		$publisher_name = get_bloginfo('name');

		// GENERATE XML
		$dom = new DOMDocument('1.0','UTF-8');
		$dom->formatOutput = true;

		// URLSET
		$urlset = $dom->createElement('urlset');
			$urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
			$urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
			$urlset->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
			$urlset->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-news/0.9 http://www.google.com/schemas/sitemap-news/0.9/sitemap-news.xsd');
 		
 		if ( $news->have_posts() ) {
 			while ( $news->have_posts() ) {
 				$news->the_post();

 				// URL
 				$url = $dom->createElement('url');
					// URL->LOC
					$url->appendChild($dom->createElement('loc', get_permalink()));

					// URL->NEWS:NEWS
					$news_schema = $dom->createElement('news:news');
						
						// NEWS:TITLE
						$news_schema->appendChild($dom->createElement('news:title', get_the_title()));
						
						// NEWS:PUBDATE
						$news_schema->appendChild($dom->createElement('news:publication_date', get_the_date('Y-m-d')));

						// NEWS:PUBLICATION
						$publication = $dom->createElement('news:publication');

							// PUBLICATION:NAME
							$publication->appendChild($dom->createElement('news:name', $publisher_name));
							
							// PUBLICATION:LANG
							$publication->appendChild($dom->createElement('news:language', 'en'));
					
					// APPEND PUBLICATION TO NEWS
					$news_schema->appendChild($publication);

				// APPEND NEWS TO URL
				$url->appendChild($news_schema);

				// ---

				// APPEND URL TO URLSET
				$urlset->appendChild($url);
 			}
 		}


 		// APPEND URLSET TO XML
 		$dom->appendChild($urlset);

 		// SAVE XML
		$dom->save($this->file) or die('XML Create Error');
		return;
	}


	/**
	 * BUILD_XML_HOURLY
	 */
	public function build_xml_hourly()
	{
		return $this->build_xml();
	}


	/**
	 * ADMIN_MENU
	 */
	public function admin_menu()
	{
		add_menu_page(
			'Google News',
			'Google News',
			'manage_options',
			'logic-google-news',
			[$this, 'admin_page'],
			'',
			null
		);
	}


	/**
	 * ADMIN_PAGE
	 */
	public function admin_page()
	{
		date_default_timezone_set('Europe/London');

		$last_modified_time = filemtime($this->file);
		$next_build_time = wp_next_scheduled('logic_rebuild_google_news_xml') - time();

		$rebuild_url = home_url(add_query_arg(['page' => 'logic-google-news', 'rebuild' => true]));
		$sitemap_url = home_url() . '/logic-google-news.xml';

		echo '<div class="wrap">';
			echo '<h1>Logic: Google News Sitemap</h1>';
			echo '<div class="card" style="max-width: 630px">';
				echo '<h2 class="title">Sync Sitemap</h2>';
				echo '<p>The sitemap will populate via scheduled task and sync with Google automatically. However should you need to manually rebuild the sitemap please click the button below.</p>';
				echo '<p>Next run in ' . human_readable_duration(gmdate('H:i:s', $next_build_time)) . '</p>';
				echo '<hr>';
				echo '<p><a href="' . $rebuild_url . '" class="button button-primary button-hero">Rebuild News Sitemap</a></p>';
				echo '<p><a target="_blank" href="' . $sitemap_url . '" class="button button-secondary">View Sitemap</a> &nbsp; <span style="opacity: .75">Last Updated: ' . date('l jS \of F Y h:i:s', $last_modified_time) . '</span></p>';
			echo '<div>';
		echo '<div>';
	}

	/**
	 * ADMIN_INIT
	 * Rebuild XML requested via $_GET['rebuild']
	 */
	public function admin_init()
	{
		global $pagenow;

		if ( 
			$pagenow === 'admin.php' 
			&& isset($_GET['page']) && $_GET['page'] === 'logic-google-news'
			&& isset($_GET['rebuild']) && $_GET['rebuild'] === '1'
		) {

			$redirect = home_url(add_query_arg([
				'page' => 'logic-google-news', 
				'rebuild' => 'complete'
			]));

			do_action('logic_build_xml');
			wp_safe_redirect($redirect);
		}
	}
}

require_once __DIR__ . '/updater/boot.php';
require_once __DIR__ . '/updater/updater.php';
require_once __DIR__ . '/updater/plugin-updater.php';

$updater = LogicGoogleNews\WP_Updater\Boot::instance();
$updater->add([
	'type' => 'plugin',
	'source' => 'https://github.com/logic-design/WP-Google-News'
]);

new Logic_Google_News();
