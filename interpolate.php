<?php

/*
 * Script to interpolate minor stop times using the surrounding
 * major stops.
 *
 * This takes a stop_times.txt file from a google transit feed
 * and outputs a new file stop_times_interpolated.txt with the
 * missing stop times filled in.
 *
 * Original use was for the Wellington GTF when they stopped
 * including minor stop times.
 *
 * Interpolates using the shape_dist_traveled assuming the buses
 * are moving at a constant speed. Interpolated stops are given
 * the same departure and arrival time and times are rounded to
 * the nearest minute.
 *
 * Output file should be the same as the input, but with the
 * missing times added. Windows line endings ('\r\n') are
 * retained.
 *
 * The script will exit with an error if it encounters a problem.
 *
 * If a row contains only one of arrival_time or departure_time,
 * it will treat it like a minor stop and recalculate both
 * values.
 *
 * @author Simon Coggins
 *
 */

$input = 'stop_times.txt';
$output = 'stop_times_interpolated.txt';

$wh = fopen($output, "w");
if (!$wh) {
    echo "Could not open $output for writing.\n";
    exit;
}

// read in and process one row at a time
$rh = fopen($input, "r");
if ($rh) {

    $last_timing_row = null; // for remembering the last timing point
    $records_to_interpolate = array(); // for storing records we need to fix

    if (($firstrow = fgets($rh, 4096)) !== false) {
        // get the header columns and output to file
        $headings = explode(',', trim($firstrow));
        write_row($wh, $headings);

        // loop through the remaining rows
        while (($buffer = fgets($rh, 4096)) !== false) {

            // read this row into an array as an associative array
            // using the heading row as keys
            $line = explode(',', trim($buffer));
            $row = array();
            foreach ($headings as $heading) {
                $row[$heading] = array_shift($line);
            }

            // main loop

            $times_missing = (empty($row['arrival_time']) && empty($row['departure_time']));

            // if there are no records that need fixing at the moment, and this
            // record has the timing info, there's nothing to do, just output
            // this timing row, store it and carry on
            if (empty($records_to_interpolate) && !$times_missing) {
                $last_timing_row = $row;
                write_row($wh, $row);
                continue;
            }

            // we need to fill in the data for this row lets append to the list
            // of rows we need to handle at the next timing row
            if ($times_missing) {
                array_push($records_to_interpolate, $row);
                continue;
            }

            // if we get here we've found the next timing row after one or more
            // rows with missing times - we need to interpolate!

            // sanity check to ensure we're still on the same trip. If not then
            // the trip didn't have timing points at the start/end. bad news!
            if ($last_timing_row === null || $last_timing_row['trip_id'] != $row['trip_id']) {
                echo "Looks like there's a trip without timing data at the start and/or end!\n";
                echo implode(',', $row) . "\n";
                fclose($rh);
                fclose($wh);
                exit;
            }

            // get time between the two timing stops
            $total_time = get_time_diff($last_timing_row['departure_time'], $row['arrival_time']);
            if ($total_time === false) {
                echo "Problem parsing the arrival or departure time from a timing row\n";
                echo "Timing departure: {$last_timing_row['departure_time']}\n";
                echo "Timing arrival: {$row['arrival_time']}\n";
                fclose($rh);
                fclose($wh);
                exit;
            }


            // get distance between the two timing stops
            $total_dist = $row['shape_dist_traveled'] - $last_timing_row['shape_dist_traveled'];

            // so for each stop to interpolate, the calculation is:
            // last timing stop departure time   +  ( (dist to this stop * total time ) / total dist )

            // loop through each missing row, fixing it then writing to file
            foreach ($records_to_interpolate as $missing_record) {
                $dist_to_this_stop =  $missing_record['shape_dist_traveled'] - $last_timing_row['shape_dist_traveled'];
                $time_to_this_stop = ( $dist_to_this_stop * $total_time ) / $total_dist;

                // calculate arrival time
                $arrival_time = add_to_time($last_timing_row['departure_time'], $time_to_this_stop);
                if ($arrival_time === false) {
                    echo "Problem parsing the departure time from a timing row\n";
                    echo "Timing departure: {$last_timing_row['departure_time']}\n";
                    fclose($rh);
                    fclose($wh);
                    exit;
                }

                // overwrite value in original row array
                $missing_record['arrival_time'] = $arrival_time;
                $missing_record['departure_time'] = $arrival_time; // same as arrival time

                // write the row
                write_row($wh, $missing_record);
            }

            // reset array for next interpolation
            $records_to_interpolate = array();

            // write out the timing row and save for next time round the loop
            write_row($wh, $row);
            $last_timing_row = $row;

        }
        if (!feof($rh)) {
            echo "Error: unexpected fgets() fail\n";
        }
    } else {
        echo "Failed to get first row!\n";
    }
    fclose($rh);
}

fclose($wh);


/**
 * Writes a row of data
 *
 * @param resource $wh File resource to be written to
 * @param array $row Array of values to write to the file handle
 *
 */
function write_row($wh, $row) {
    fwrite($wh, implode(',', $row) . "\r\n");
}

/**
 * Given a time in HH:MM:SS format and a number of seconds, calculate a new
 * HH:MM:SS time rounding to the nearest minute
 *
 * @param string $time Time in HH:MM:SS format
 * @param integer $secs Number of seconds to add
 *
 * @return string|false New time in HH:MM:SS or false if parsing failed
 */
function add_to_time($time, $secs) {
    // we need an arbritary date
    // to provide support for dates
    // rolling past midnight
    $date = '2000-01-01';
    $str = "$date $time";
    $unixtime = strtotime($str);
    // failed to parse time
    if ($unixtime === false) {
        return false;
    }

    $newtime = $unixtime + $secs;

    // round seconds to the nearest minute:
    // 0-29 rounded down
    // 30-59 rounded up
    $secs_to_round = $newtime % 60;
    if ($secs_to_round >= 30) {
        $newtime += 60 - $secs_to_round;
    } else {
        $newtime -= $secs_to_round;
    }

    // return HH:MM:SS
    return strftime('%T', $newtime);
}


/**
 * Given two times in HH:MM:SS format, return the number of seconds between them
 *
 * If t2 < t1, assume t2 occurred the next day
 *
 * @param string $t1 Time in HH:MM:SS
 * @param string $t2 Time in HH:MM:SS
 *
 * @return integer|false Number of seconds or false if time is in wrong format
 */
function get_time_diff($t1, $t2) {
    // we need an arbritary date
    // to provide support for dates
    // rolling past midnight
    $date = '2000-01-01';
    $str1 = "$date $t1";
    $str2 = "$date $t2";
    $time1 = strtotime($str1);
    $time2 = strtotime($str2);
    // failed to parse times
    if ($time1 === false || $time2 === false) {
        return false;
    }
    // if time1 appears to be later than time2 we may be working across a date boundary
    // e.g. around midnight
    // add a day to time2
    if ($time1 > $time2 && $time1 - $time2 < 86400) {
        $time2 += 86400;
    }

    return $time2 - $time1;

}

