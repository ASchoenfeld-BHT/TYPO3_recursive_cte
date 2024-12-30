-- Logging and caching setup
FLUSH QUERY CACHE;
SHOW STATUS LIKE "Qcache%";
SET GLOBAL general_log = 1;
SET GLOBAL log_output = 'Table';
show variables like "%log_output%";
show variables like "%general_log%";


-- Set timestamp variable
SET @timestmp = CURRENT_TIMESTAMP;


-- CTE to get logging statistics
WITH
	data_time AS (
		SELECT * FROM mysql.general_log WHERE `event_time` > SUBTIME(@timestmp, "0:1:0") AND `event_time` < SUBTIME(@timestmp, "-0:1:0")
	),
    data_prepare AS (
		SELECT * FROM data_time WHERE `command_type` = 'Prepare'
	),
    data_execute AS (
		SELECT * FROM data_time WHERE `command_type` = 'Execute'
	),
    data_close AS (
		SELECT * FROM data_time WHERE `command_type` = 'Close stmt'
	),
    data_query AS (
		SELECT * FROM data_time WHERE `command_type` = 'Query'
	),
    data_quit AS (
		SELECT * FROM data_time WHERE `command_type` = 'Quit'
	),
    data_connect AS (
		SELECT * FROM data_time WHERE `command_type` = 'Connect'
	),
    statistics AS (
		SELECT
			(SELECT SUBTIME(@timestmp, "0:1:0")) AS Timerange_Start,
			(SELECT SUBTIME(@timestmp, "-0:1:0")) AS Timerange_End,
			(SELECT COUNT(*) FROM data_connect) AS Connect_Count,
			(SELECT COUNT(*) FROM data_quit) AS Quit_Count,
			(SELECT COUNT(*) FROM data_prepare) AS Prepare_Count,
			(SELECT COUNT(*) FROM data_execute) AS Execute_Count,
            (SELECT COUNT(*) FROM data_close) AS Close_Count,
			(SELECT COUNT(*) FROM data_query) AS Query_Count
	)
-- Uncomment this to output every executed (prepared)statement 
-- SELECT *
-- FROM data_execute
-- ORDER BY `event_time` ASC;

-- Use this to get the number of all statements
SELECT *
FROM statistics;


    
-- -----------------------------
-- Additional: useful statements
-- -----------------------------

-- Get number of pages
select count(uid) from pages;

-- Clear the logging table
SET GLOBAL general_log = 'OFF';
RENAME TABLE mysql.general_log TO mysql.general_log_temp;
DELETE FROM mysql.general_log_temp WHERE `event_time` < CURRENT_TIMESTAMP;
RENAME TABLE mysql.general_log_temp TO mysql.general_log;
SET GLOBAL general_log = 'ON';


-- Create the logging table if accidentally deleted
CREATE TABLE mysql.general_log (
  `event_time` timestamp(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `user_host` mediumtext NOT NULL,
  `thread_id` bigint(21) unsigned NOT NULL,
  `server_id` int(10) unsigned NOT NULL,
  `command_type` varchar(64) NOT NULL,
  `argument` mediumtext NOT NULL
) ENGINE=CSV DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='General log';
