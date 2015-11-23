<?php

namespace Bitbucket;


class Bitbucket
{

    /**
     * Return an array of repositories from Bitbucket
     * @return array
     */
    function repositories($bitbucket_team)
    {
        $repositories = array();

        $repositories_json = $this->get('https://bitbucket.org/api/2.0/repositories/' . $bitbucket_team . '?pagelen=100');

        $repositories_array = json_decode($repositories_json, true);

        if (is_array($repositories_array) && !empty($repositories_array)) {
            foreach ($repositories_array['values'] as $repository) {
                $repositories[] = array(
                    'bitbucket_team' => $bitbucket_team,
                    'name' => $repository['name'],
                    'updated_on' => date("Y-m-d H:i:s", strtotime($repository['updated_on'])),
                    'description' => $repository['description']
                );
            }
        }

        return $repositories;
    }

    /**
     * Count the number of change sets in a repo
     *
     * @param $bitbucket_team
     * @param $repository
     */
    function count_changesets($bitbucket_team, $repository)
    {
        $changeset_count = 0;

        $changesets_json = $this->get('https://bitbucket.org/api/1.0/repositories/' . $bitbucket_team . '/' . $repository . '/changesets?limit=0');

        $changeset_array = json_decode($changesets_json, true);

        return $changeset_array['count'];

    }

    /**
     * Given an array of changesets, filter it down to just the ones between 2 date ranges
     *
     * @param $changesets
     * @param $start_time
     * @param $end_time
     * @return array
     */
    function filter_changesets($changesets, $start_time, $end_time)
    {
        $filtered_changesets = array();
        $start_date = date("Y-m-d H:i:s", $start_time);
        $end_date = date("Y-m-d H:i:s", $end_time);

        foreach ($changesets as $timestamp => $change) {
            if (date("Y-m-d H:i:s", strtotime($timestamp)) >= $start_date && date("Y-m-d H:i:s", strtotime($timestamp)) <= $end_date) {
                $filtered_changesets[$timestamp] = $change;
            }
        }

        return $filtered_changesets;
    }

    /**
     * Given an array of changesets, loop through them and only get the data we need
     *
     * @param $changesets_array
     * @return array
     */
    function clean_changesets_array($changesets_array)
    {
        $clean_changesets = array();

        if (is_array($changesets_array) && !empty($changesets_array)) {
            foreach ($changesets_array as $changeset) {
                $clean_changesets[$changeset['timestamp']] = array(
                    'raw_author' => $changeset['raw_author'],
                    'timestamp' => $changeset['utctimestamp'],
                    'raw_node' => $changeset['raw_node'],
                    'message' => trim($changeset['message']),
                    'files' => $changeset['files'],
                );
            }
        }

        return $clean_changesets;
    }

    /**
     * Given a Team and Repository name, return all changesets
     * Use changesets_all() if there are > 50 changesets
     *
     * @param $bitbucket_team
     * @param $repository
     * @return array|mixed
     */
    function changesets($bitbucket_team, $repository)
    {
        $changesets_json = $this->get('https://bitbucket.org/api/1.0/repositories/' . $bitbucket_team . '/' . $repository . '/changesets?limit=50');

        $changesets_array = json_decode($changesets_json, true);

        $changesets_array = $changesets_array['changesets'];

        $changesets_array = $this->clean_changesets_array($changesets_array);

        return $changesets_array;
    }

    /**
     * Give a Team and a Repo, return all changesets
     * Use Changesets() to get the most recent 50 if you don't want all changesets
     *
     * @param $bitbucket_team
     * @param $repository
     * @param int $total_changesets
     * @return array
     */
    function changesets_all($bitbucket_team, $repository, $total_changesets = 0)
    {
        $final_changesets = array();
        $changesets_array = array();
        $changesets_json = '';
        $counter = 1;

        $loops = (int)ceil($total_changesets / 50);
        $start_at = '';

        //echo "Loops to perform: $loops<br />\n";

        do {

            //echo "[$counter] Changesets starting from: $start_at<br />\n";

            $counter++;

            if ($start_at == '') {
                $changesets_json = $this->get('https://bitbucket.org/api/1.0/repositories/' . $bitbucket_team . '/' . $repository . '/changesets?limit=50');
            } else {
                $changesets_json = $this->get('https://bitbucket.org/api/1.0/repositories/' . $bitbucket_team . '/' . $repository . '/changesets?limit=50&start=' . $start_at);
            }

            $changesets_array = json_decode($changesets_json, true);

            $start_at = ($changesets_json != '') ? $changesets_array['changesets'][0]['raw_node'] : $start_at;

            $current_changesets_array = $this->clean_changesets_array($changesets_array['changesets']);

            $final_changesets = array_merge($final_changesets, $current_changesets_array);

            $loops--;

        } while ($loops >= 1);

        return $final_changesets;
    }

    /**
     * Given an array of changesets, determine the time taken between tasks
     * If an average_task_time is provided, then use that for any unknowns
     *
     * @param $changesets_array
     * @param int $average_task_time
     * @return array
     */
    public function calculate_task_time($changesets_array, $average_task_time = 0)
    {
        $updated_changesets = array();
        $last_timestamp = '';

        foreach ($changesets_array as $timestamp => $changeset) {
            $changeset['time_taken'] = $this->determine_time_taken($changeset['timestamp'], $last_timestamp, $average_task_time);
            $updated_changesets[$timestamp] = $changeset;
            $last_timestamp = $changeset['timestamp'];
        }

        return $updated_changesets;
    }

    /**
     * Given an array of changesets with 'time_taken', work out the average time_taken
     *
     * @param $changesets_array
     * @return int Time taken in seconds
     */
    public function calculate_average_from_tasks($changesets_array)
    {
        $time_taken = 0;
        $number_of_commits = 0;

        foreach ($changesets_array as $change) {
            if ($change['time_taken'] != 0) {
                $time_taken += $change['time_taken'];
                $number_of_commits++;
            }

        }

        return (int)($time_taken / $number_of_commits);
    }

    /**
     * Given 2 times, return the number of minutes in between them
     *
     * @param $new_time
     * @param string $old_time
     * @param $average_task_time
     * @return int = Time taken in seconds
     */
    private function determine_time_taken($new_time, $old_time = '', $average_task_time)
    {
        $default = $average_task_time;
        $difference = 0;

        if ($old_time == '') {
            $difference = $default;
        } else {

            $new_timestamp = strtotime($new_time);
            $old_timestamp = strtotime($old_time);

            if (date("d", $new_timestamp) == date("d", $old_timestamp)) { // if old and new are on the same day

                $difference = ($new_timestamp - $old_timestamp);

                $lunch_time_start = strtotime(date("Y-m-d", strtotime($new_time)) . ' 13:00:00');
                $lunch_time_end = strtotime(date("Y-m-d", strtotime($new_time)) . ' 14:00:00');
                $finish_time = strtotime(date("Y-m-d", strtotime($new_time)) . ' 17:00:00');

                if ($old_timestamp < $lunch_time_start && $lunch_time_end < $new_timestamp) {
                    // take off an hour for lunch time
                    $difference = $difference - 3600; // remove 60 minutes for lunch time
                }

                if (($old_timestamp < $finish_time) && ($finish_time < $new_timestamp)) {
                    // if a commit was made later in the evening after working hours then reset back to the default
                    $difference = $default;
                }

            } else {
                $difference = $default;
            }
        }

        return (int)$difference;
    }

    /**
     * Given an array of changsets, determine the total time taken from 'time_taken'
     *
     * @param $changesets_array
     * @return int Total Hours in Hours
     */
    function calculate_total_hours($changesets_array)
    {
        $total_seconds = 0;
        foreach ($changesets_array as $change) {
            $total_seconds = $total_seconds + $change['time_taken'];
        }

        return (int)($total_seconds / 3600);
    }

    /**
     * Perform the lookup on Bitbucket using Curl
     *
     * @param $api_endpoint
     * @return mixed
     */
    function get($api_endpoint)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1");
        curl_setopt($ch, CURLOPT_URL, $api_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $_ENV['BITBUCKET_USERNAME'] . ":" . $_ENV['BITBUCKET_PASSWORD']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $response_content = curl_exec($ch);

        if (curl_error($ch) != '') {
            echo 'Curl error: ' . curl_error($ch) . "<br />\n";
        }

        curl_close($ch);

        return $response_content;
    }

}