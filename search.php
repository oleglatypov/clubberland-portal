<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Created by PhpStorm.
 * User: oleg.latypov
 * Date: 1/21/14
 * Time: 8:52 PM
 */
/****************************************************************/
class Search extends CI_Controller
{
    /****************************************************************/
    function __construct()
    {
        parent::__construct();

        // Not required if you autoload the library
        $this->load->model('Bage');
        $this->load->model('Tag');
        $this->load->model('User');
        $this->load->model('Event');
        $this->load->model('Venue');
        $this->load->model('Media');
    }
    /****************************************************************/
    function index()
    {
        die();
    }
    /****************************************************************/
    private function build_filters($get)
    {
        $filters = array();

        if (isset($get['with_friends']) && $get['with_friends'] == 1)
        {
            $user_session = $this->session->userdata('user');
            $friends_list_ids = array();
            if (isset($user_session['id']))
            {
                $friends = $this->User->get_friends_by_user_id($user_session['id']);
                foreach($friends as $user_data)
                {
                    $friends_list_ids[] = $user_data['user_id'];
                }
                $filters['user_id'] = $friends_list_ids;
            }
        }

        if ( isset($get['distance_max']) AND isset($get['distance_min']) )
        {
            // Distance to events From Chosen Zip code
            if (isset($get['distance_from_zip']) AND $get['distance_from_zip'] != '') {
                
                if ($location = $this->get_location_by_zip($get['distance_from_zip'])) {
                    $filters['latitude']  = $location['lat'];
                    $filters['longitude'] = $location['long'];
                }

            // Distance to events from current location (from your IP)
            } else {

                if($location = $this->get_location() ){
                    $filters['latitude']  = $location['lat'];
                    $filters['longitude'] = $location['long'];
                }
            }

            $filters['radius_min'] = floatval($get['distance_min']);
            $filters['radius_max'] = floatval($get['distance_max']);
        } else {
            $filters['radius_min'] = 0;
            $filters['radius_max'] = 1000;
        }

        if (!empty($get['tag_id']) && is_array($get['tag_id']))
        {
            $filters['tag_id']  = $get['tag_id'];
        }

        if (!empty($get['start_date']) && !empty($get['end_date']))
        {
            $start_date = new DateTime($get['start_date']);
            $end_date = new DateTime($get['end_date']);
            date_add($end_date, date_interval_create_from_date_string('12 hours'));


            $filters['start_date']  = $start_date->getTimestamp();
            $filters['end_date']  = $end_date->getTimestamp();
        }

        if(isset($get['status']))
        {
            $filters['status'] = $get['status'];
        } else {
            $filters['status'] = 1;
        }



        if (!empty($get['cat_id']) && is_array($get['cat_id']))
        {
            $filters['cat_id']  = $get['cat_id'];
        }


        if (!empty($get['music_id']) && is_array($get['music_id']))
        {
            $filters['music_id']  = $get['music_id'];
        }

        // limit Events and Offset
        if(isset($get['limit']) AND intval($get['limit']) > 0 AND intval($get['limit']) < 201)
        {
            $filters['offset'] = isset($get['offset']) ? intval($get['offset']) : 1+0;

            $filters['limit'] = intval($get['limit']);
        } else {
            $filters['limit'] = 5;
            $filters['offset'] = 0;
        }

        return $filters;
    }
    /****************************************************************/
    private function sphinxSearch($keyword = null, $filters = array(),$source = null)
    {
        $sphinx_db_conf = $this->load->database('sphinx', TRUE);
        $sphinx = new SphinxClient();
        $sphinx->SetServer($sphinx_db_conf->hostname, $sphinx_db_conf->port);
        $sphinx->SetSortMode(SPH_SORT_RELEVANCE);

        if (!empty($filters))
        {
            if (isset($filters['status']))
            {
                $status = $filters['status'];
                $sphinx->setFilter('status', array($status));
            }

            if (isset($filters['start_date']) && isset($filters['end_date']))
            {
                

                $start_date = $filters['start_date'];
                $end_date = $filters['end_date'];
                $sphinx->setFilterRange('start_date', $start_date, $end_date);
//                $start_date = $filters['start_date'];
//                $sphinx->setFilter('start_date', array($start_date));
//                $end_date = $filters['end_date'];
//                $sphinx->setFilter('end_date', array($end_date));
            }
            elseif (isset($filters['start_date']))
            {
                $start_date = $filters['start_date'];
                $sphinx->setFilter('start_date', array($start_date));
            }

            if (!empty($filters['latitude']) && !empty($filters['longitude']) 
                && isset($filters['radius_min']) && isset($filters['radius_max']) )
            {

                $latitude = $filters['latitude'];
                $longitude = $filters['longitude'];

                // miles(1.61*1000) to meters
                $radius_min = floatval($filters['radius_min']*1.61*1000); 
                $radius_max = floatval($filters['radius_max']*1.61*1000);


                $sphinx->SetMatchMode(SPH_MATCH_ALL);
                $sphinx->setGeoAnchor('latitude', 'longitude', (float) deg2rad($latitude), (float) deg2rad($longitude) );
                $sphinx->SetFilterFloatRange('@geodist', $radius_min, $radius_max);
                 $sphinx->SetSortMode(SPH_SORT_EXTENDED, '@geodist ASC');
            }


            if(!empty($filters['user_id']) && is_array($filters['user_id'])){
                $sphinx->setFilter('user_id', $filters['user_id']);
            }

            if(!empty($filters['tag_id']) && is_array($filters['tag_id'])){
                $sphinx->setFilter('tag_id', $filters['tag_id']);
            }

            if(!empty($filters['cat_id']) && is_array($filters['cat_id'])){
                $sphinx->setFilter('cat_id', $filters['cat_id']);
            }

            if(!empty($filters['music_id']) && is_array($filters['music_id'])){
                $sphinx->setFilter('music_id', $filters['music_id']);
            }

            if( isset($filters['limit']) && isset($filters['offset']) ){
                $sphinx->setLimits( intval($filters['offset']), intval($filters['limit']) );
            }
        }

        if ( $sphinx->GetLastWarning() ) {
            echo "WARNING: " . $sphinx->GetLastWarning();
        }

        $result = $sphinx->Query($keyword,$source);
        if ( $result === false ) {
            echo "Query failed: " . $sphinx->GetLastError() . ".\n"; // выводим ошибку если произошла
            die();
        }

        return $result;
    }

    public function get_events()
    {


        header('Content-type: application/json');

        $get = $this->input->get();
        $keyword = isset($get['keyword']) ? $get['keyword'] : null;

        $filters = $this->build_filters($get);


        $sphinxResult = $this->sphinxSearch($keyword, $filters, 'events events_delta');


        $event_ids = array();

        if (!empty($sphinxResult['matches']))
        {
            $event_ids = array_keys($sphinxResult['matches']);
        }
        
        $json = array();
        $json['count_events'] = $sphinxResult["total_found"];
        
        if (empty($event_ids))
        {
            echo json_encode($json);
            exit;
        }

        $json['events'] = array();
        $json['venues_category'] = array();
        $json['venues_music'] = array();
        $json['venues_category'] = $this->Venue->get_venue_category();
        $json['venues_music'] = $this->Venue->get_venues_music();

        $result = array(
            'details' => $this->Event->get_by_event_id($event_ids),
            'venues' => $this->Venue->get_by_event_id($event_ids),
            'users' => $this->User->get_by_event_id($event_ids),
            'bages' => $this->Bage->get_by_event_id($event_ids),
            'tags' => $this->Tag->get_by_event_id($event_ids),
            'media' => $this->Media->get_by_entity_id($event_ids, 'event')
        );



        foreach ($event_ids as $event_id)
        {
            $json['events'][$event_id]['details'] = array();
            $json['events'][$event_id]['venue'] = array();
            $json['events'][$event_id]['users'] = array();
            $json['events'][$event_id]['bages'] = array();
            $json['events'][$event_id]['tags']  = array();
            $json['events'][$event_id]['media'] = array();
        }

        foreach ($result['details'] as $event_details)
        {
            $event_id = $event_details['event_id'];

            if ( isset( $event_details['start_date'] ) ) {
                $currentTime = DateTime::createFromFormat( 'U', $event_details['start_date'] );
                $event_details['start_date'] = $currentTime->format('Y-m-d | g:i A');
            }
            
            if ( isset( $event_details['end_date'] ) ) {
                $currentTime = DateTime::createFromFormat( 'U', $event_details['end_date'] );
                $event_details['end_date'] = $currentTime->format('Y-m-d | g:i A');
            }

            $json['events']  [$event_id]['details'] = $event_details;

        }

        foreach ($result['venues'] as $venue_details)
        {
            if(isset($venue_details['biz_music'])){
                $venue_details['biz_music'] = $this->Venue->get_venue_music($venue_details['biz_music']);
            }
            $event_id = $venue_details['event_id'];
            $json['events']  [$event_id]['venue'] = $venue_details;
        }

        foreach ($result['users'] as $user_details)
        {
            $event_id = $user_details['event_id'];
            $json['events']  [$event_id]['users'][] = $user_details;
        }

        $bage_ids = array();
        foreach ($result['bages'] as $bage_index => $bage_details)
        {
            $event_id = $bage_details['event_id'];
            $json['events'][$event_id]['bages'][$bage_index] = $bage_details;
            $json['events'][$event_id]['bages'][$bage_index]['media'] = array();
            $bage_ids[] = $bage_details['bage_id'];
        }

        if (!empty($bage_ids))
        {
            $bage_ids = implode(',', $bage_ids);
            $bages_media = $this->Media->get_by_entity_id($bage_ids, 'bage');
            foreach ($bages_media as $bages_media_info)
            {
                foreach ($result['bages'] as $bage_index => $bage_details)
                {
                    if ($bage_details['bage_id'] === $bages_media_info['bage_id'])
                    {
                        //$result['bages'][$bage_index]['media'][] = $bages_media_info;
                        $event_id = $bage_details['event_id'];
                        $json['events'][$event_id]['bages'][$bage_index]['media'] = $bages_media_info;
                    }
                }
            }

        }

        foreach ($result['tags'] as $tag_details)
        {
            if ( isset($bage_details['event_id']) ) {
                $event_id = $bage_details['event_id'];
                $json['events'] [$event_id]['tags'][] = $tag_details;
            }
        }

        foreach ($result['media'] as $media_details)
        {
            $event_id = $media_details['event_id'];
            $json['events'][$event_id]['media'][] = $media_details;
        }

        echo json_encode($json);
    }

    /****************************************************************/
    public function get_venues()
    {
        header('Content-type: application/json');
        $get = $this->input->get();

        $keyword = isset($get['keyword']) ? $get['keyword'] : null;

        $filters['limit'] = isset($get['limit'])?$get['limit']:20;
        $filters['offset'] = isset($get['offset'])?$get['offset']:0;

        $sphinxResult = $this->sphinxSearch($keyword,$filters, 'venues');

        $venueIds = array();
        $json = array();

        if (!empty($sphinxResult['matches']))
        {
            $venueIds = array_keys($sphinxResult['matches']);
        }else{
            echo json_encode($json);
            exit;
        }

        $json['count_venues'] =  $sphinxResult['total_found'];

        $venueIds = implode(',', $venueIds);
        $json['venues'] = $this->Venue->get_by_venue_id($venueIds);

        echo json_encode($json);
    }

    public function get_map()
    {
        header('Content-type: application/json');
        $filters = $this->build_map_filters($this->input->get());
        $sphinx_result = $this->map_sphinx_search('',$filters, 'venues_indexer');
        $venueIds = array();
        $json = array();

        if (!empty($sphinx_result['matches']))
        {
            $venueIds = array_keys($sphinx_result['matches']);
        }else{
            echo json_encode($json);
            exit;
        }

        $venueIds = implode(',', $venueIds);
        $venues = $this->Venue->get_by_venue_id($venueIds);
        $json['html_attributions'] = array();
        foreach($venues as $venue){
            $json['results'][]['geometry']['location'] = array("lat" => $venue['loc_LAT_poly'], "lng" => $venue['loc_LONG_poly'], "name" => $venue['biz_name'] , "address" => '' , "url" => htmlentities($venue['web_url']) , "html" => '', "category" => '' , "icon" => '');
        }
        $json['status'] = 'OK';

        echo json_encode($json);
    }


    public function build_map_filters($data)
    {
        $extendLat = $data['extendLat'];
        $extendLng = $data['extendLng'];
        $filters['southWestLat'] = $data['swLat'] - $extendLat;
        $filters['northEastLat'] = $data['neLat'] + $extendLat;
        $filters['southWestLng'] = $data['swLng'] - $extendLng;
        $filters['northEastLng'] = $data['neLng'] + $extendLng;
        $filters['limit'] = 1000;
        $filters['offset'] = 0;
        return $filters;
    }


    private function map_sphinx_search($keyword = null, $filters = array(),$source = null)
    {
        $sphinx_db_conf = $this->load->database('sphinx', TRUE);
        $sphinx = new SphinxClient();
        $sphinx->SetServer($sphinx_db_conf->hostname, $sphinx_db_conf->port);
        $sphinx->SetSortMode(SPH_SORT_RELEVANCE);
        $sphinx->SetMatchMode(SPH_MATCH_ALL);
        $sphinx->setFilterFloatRange('latitude',(float) deg2rad($filters['southWestLat']),(float) deg2rad($filters['northEastLat']));
        $sphinx->setFilterFloatRange('longitude',(float) deg2rad($filters['southWestLng']),(float) deg2rad($filters['northEastLng']));
        if( isset($filters['limit']) && isset($filters['offset']) ){
            $sphinx->setLimits( intval($filters['offset']), intval($filters['limit']) );
        }
        $result = $sphinx->Query($keyword,$source);

        return $result;
    }

    function get_location(){

        $ip_addr = $_SERVER['REMOTE_ADDR'];


        //@TODO REMOVE THIS LINE IN PRODUCTION
        $ip_addr = '68.198.74.169'; // Brooklyn coordinates

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://www.geoplugin.net/php.gp?ip=' . $ip_addr);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($location = curl_exec($ch)) {
            $location = unserialize($location);     
        } 
        curl_close($ch);

        $response = array();

        if (is_array($location) AND isset($location['geoplugin_status']) AND 
            $location['geoplugin_status'] == 200) {

            $response['lat'] = floatval($location['geoplugin_latitude']);
            $response['long'] = floatval($location['geoplugin_longitude']);
            $response['region'] = safe_html($location['geoplugin_regionCode']);
            $response['city'] = safe_html($location['geoplugin_city']);
            $response['ip'] = safe_html($location['geoplugin_request']);

            return $response;
        } else {
            return false;
        }


    }

    function get_location_by_zip($zip){

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://maps.googleapis.com/maps/api/geocode/json?address='.intval($zip).'&sensor=false');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($location = curl_exec($ch)) {
            $location = json_decode($location);     
        } 
        curl_close($ch);

        $response = array();

        if (isset($location->results) AND isset($location->results[0]) AND
            $location->status == "OK") {

            $response['lat']  = floatval( $location->results[0]->geometry->location->lat );
            $response['long'] = floatval( $location->results[0]->geometry->location->lng );
            // $response['region'] = safe_html( $location->results[0]->formatted_address );
            // $response['city']   = safe_html( $location->results[0]->address_components[3]->long_name );
            // $response['zip']    = safe_html( $location->results[0]->address_components[0]->long_name );

            return $response;
        } else {
            return false;
        }


    }
}
/****************************************************************/