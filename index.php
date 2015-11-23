<?php

require __DIR__ . '/bootstrap.php';

$app->get('/', function () use ($app) {

    return 'Hello';

});

/**
 *  Work out the days worked between 2 days for each developer
 */
$app->get('/days_worked/{format}', function ($format) use ($app, $bitbucket, $bitbucket_teams) {

    $current_date = date("Y-m-d");
    $start_date = strtotime('-8 days', strtotime($current_date . ' 12:00:00')); // 'from'
    $end_date = strtotime('-1 days', strtotime($current_date . ' 11:59:59')); // 'to'

    echo "Between " . date("d/m/Y H:i:s", $start_date) . " and " . date("d/m/Y H:i:s", $end_date) . "<br />\n";

    $activity = array();
    $repositories = array();

    // compile an array of repositories across several Bitbucket accounts/teams
    foreach ($bitbucket_teams as $bitbucket_team) {
        $repositories = array_merge($repositories, $bitbucket->repositories($bitbucket_team));
    }

    foreach ($repositories as $repository) {

        $repository_updated = strtotime($repository['updated_on']);

        if ($repository_updated >= $start_date && $repository_updated <= $end_date) {

            //echo "Looking at: " . $repository['name'] . " (Last updated on " . date("d/m/Y H:i:s", $repository_updated) . ")<br />\n";
            //print_r($repository);

            $changesets = $bitbucket->changesets_all($repository['bitbucket_team'], $repository['name']);

            $changesets_filtered = $bitbucket->filter_changesets($changesets, $start_date, $end_date);

            foreach ($changesets_filtered as $change) {

                if (!isset($activity[$change['raw_author']]) || !in_array(date("Y-m-d", strtotime($change['timestamp'])), $activity[$change['raw_author']])) {
                    $activity[$change['raw_author']][] = date("Y-m-d", strtotime($change['timestamp']));
                }
            }
        } else {
            // echo "Skipped " . $repository['name'] . " (" . date("d/m/Y", $repository_updated) . ") <br />\n";
        }
    }

    /**
     * Email the days worked by each developer
     */
    if ($format == 'email') {

        $email_content = "Hi, \r\n\r\nBased on Bitbucket data, the following dates had development activity, from " . date("l", $start_date) . " " . date("d/m/Y", $start_date) . " to " . date("l", $end_date) . " " . date("d/m/Y", $end_date) . " inclusive.\r\n";

        foreach ($activity as $developer => $dates_worked) {

            asort($dates_worked);

            preg_match("/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $developer, $email_address_array);
            $email_address = current($email_address_array);

            $email_content .= "\r\n";
            $email_content .= "Here are the dates for $developer:\r\n\r\n";

            foreach ($dates_worked as $date) {
                $email_content .= date("l", strtotime($date)) . " - " . date("d/m/Y", strtotime($date)) . "\r\n";
            }
        }

        $email_content .= "\r\n";
        $email_content .= "Thanks,\r\n";
        $email_content .= "\r\n" . $_ENV['BUSINESS_OWNER_NAME'];

        $message = \Swift_Message::newInstance()
            ->setSubject($_ENV['BUSINESS_NAME'] . ' development activity, ' . date("d/m/Y", $start_date) . " to " . date("d/m/Y", $end_date))
            ->setFrom(array($_ENV['SEND_EMAIL_FROM']))
            ->setTo(array($_ENV['SEND_EMAIL_TO']))
            ->setBody($email_content);

        $app['mailer']->send($message);

    } else {
        print_r($activity);
    }

    return '';

})->value('format', 'list');

/**
 * Show all the repositories and commits that developers made to them within the time period.
 */
$app->get('/days_worked_details', function () use ($app, $bitbucket, $bitbucket_teams) {

    $current_date = date("Y-m-d");

    $start_date = strtotime('-25 days', strtotime($current_date)); // 'from'
    $end_date = strtotime('-14 days', strtotime($current_date)); // 'to'

    echo "Between " . date("d/m/Y", $start_date) . " and " . date("d/m/Y", $end_date) . "<br />\n";

    $activity = array();
    $repositories = array();

    // compile an array of repositories across several Bitbucket accounts/teams
    foreach ($bitbucket_teams as $bitbucket_team) {
        $repositories = array_merge($repositories, $bitbucket->repositories($bitbucket_team));
    }

    foreach ($repositories as $repository) {

        if (isset($repository['updated_on']) && $repository['updated_on'] != '') {

            if (strtotime($repository['updated_on']) >= $start_date && strtotime($repository['updated_on']) <= $end_date) {

                $changesets = $bitbucket->changesets($repository['bitbucket_team'], $repository['name'], $start_date, $end_date);

                foreach ($changesets as $change) {

                    $change_author = $change['raw_author'];
                    $change_date = date("Y-m-d", strtotime($change['timestamp']));
                    $change_time = date("H:ia", strtotime($change['timestamp']));
                    $change_repository = $change['repository'];
                    $change_message = $change['message'];

                    $activity[$change_author][$change_date][$change_repository][$change_time] = preg_replace('/\s+/', ' ', $change_message);

                }
            }
        }
    }

    print_r($activity);

    return '';

});

/**
 * List all Bitbucket Repositories
 */
$app->get('/projects', function () use ($app, $bitbucket, $bitbucket_teams) {

    $repositories = array();

    // compile an array of repositories across several Bitbucket accounts/teams
    foreach ($bitbucket_teams as $bitbucket_team) {
        $repositories = array_merge($repositories, $bitbucket->repositories($bitbucket_team));
    }

    echo "<ol>\n";
    foreach ($repositories as $repository) {
        echo "<li><a href='project_commits/" . $repository['bitbucket_team'] . "/" . $repository['name'] . "'>" . $repository['bitbucket_team'] . "/" . $repository['name'] . "</a></li>";
    }
    echo "</ol>\n";

    return '';

});

/**
 *  Given a Team and a Repo, work out all changesets and time taken
 */
$app->get('/project_commits/{team}/{repo}', function ($team, $repo) use ($app, $bitbucket, $bitbucket_teams) {

    $number_of_changesets = $bitbucket->count_changesets($team, $repo);

    //echo "Initial changesets count: $number_of_changesets<br />\n";

    $changesets_array = ($number_of_changesets <= 50) ? $bitbucket->changesets($team, $repo, $number_of_changesets) : $bitbucket->changesets_all($team, $repo, $number_of_changesets);

    //echo count($changesets_array);

    ksort($changesets_array); // sort by key (which is the timestamp)

    $changesets_array = $bitbucket->calculate_task_time($changesets_array);

    $average_task_time = $bitbucket->calculate_average_from_tasks($changesets_array);

    $changesets_array = $bitbucket->calculate_task_time($changesets_array, $average_task_time);

    $total_hours = $bitbucket->calculate_total_hours($changesets_array);

    //print_r($changesets_array);

    /*
     * calculate hours per day.
     */
    $days_worked = array();

    foreach ($changesets_array as $date => $changes) {

        $date = date("d/m/Y", strtotime($date));

        if (!isset($days_worked[$date])) {
            $days_worked[$date] = 0;
        }

        $days_worked[$date] += round(($changes['time_taken'] / 60 / 60), 1);

    }

    $total_cost = ($total_hours * 50);
    $total_cost_to_murrion = ($total_hours * 15);

    echo "Total changesets: " . count($changesets_array) . ". Total Hours: $total_hours. Total cost to Murrion: $total_cost_to_murrion. Total cost to the client: $total_cost. Average Task time $average_task_time seconds<br />";

    //print_r($days_worked);

    $total_hours_check = 0;
    foreach ($days_worked as $date => $hours) {
        $total_hours_check += $hours;
    }

    echo "Total hours:" . $total_hours_check;

    return '';

});

$app->run();