-- Using a variable for workspace, pid and max traversal levels just for convinience
-- For production replace @selectedWS in getAllWsPages and @maxTraversalLevel + @selectedPid in rcte
SET @selectedWS = CAST(0 AS int);
SET @maxTraversalLevel = CAST(20 AS int);
SET @selectedPid = CAST(0 AS int);

WITH
	-- Step 1: Get all live pages
	getAllLivePages AS (
		SELECT uid, pid, title, sorting, t3ver_oid, t3ver_wsid, t3ver_state, t3ver_stage, deleted 
        FROM pages 
        WHERE deleted = 0 AND t3ver_wsid=0
	),
    -- Step 1: Get all workspace pages
    getAllWsPages AS (
		SELECT uid, pid, title, sorting, t3ver_oid, t3ver_wsid, t3ver_state, t3ver_stage, deleted 
        FROM pages 
        WHERE deleted = 0 AND t3ver_wsid=@selectedWS
	),
    -- Step 2: Get live pages excluding pages changed/new in workspace
    getAllLivePages_exclWsEdit AS (
		-- Alternativ with 'NOT IN'
        -- SELECT a.uid as '__CTE_ID__', a.uid, a.pid, a.title, a.sorting, a.t3ver_oid, a.t3ver_wsid, a.t3ver_state, a.t3ver_stage, a.deleted FROM data_live_all a WHERE a.uid NOT IN (SELECT t3ver_oid FROM data_ws_all)
        SELECT a.uid AS '__ORIG_UID__', a.uid, a.pid, a.title, a.sorting, a.t3ver_oid, a.t3ver_wsid, a.t3ver_state, a.t3ver_stage, a.deleted 
        FROM getAllLivePages a 
        LEFT JOIN getAllWsPages b ON a.uid = b.t3ver_oid 
        WHERE b.t3ver_oid IS NULL
	),
    -- Step 2: Get workspace overlay for changed/moved/deleted pages
	getAllWsPages_exclUnchangedLivePages AS (
		SELECT a.uid AS '__ORIG_UID__', a.t3ver_oid AS 'uid', a.pid, a.title, a.sorting, a.t3ver_oid, a.t3ver_wsid, a.t3ver_state, a.t3ver_stage, a.deleted 
        FROM getAllWsPages a 
        JOIN getAllLivePages b ON a.t3ver_oid = b.uid 
        WHERE a.t3ver_oid!=0  -- 'WHERE a.t3ver_oid!=0' not really necessary but added for safety
	),
    -- Step 2: Get new pages and placeholders in workspace (created in ws, but not present in live data)
    getAllWsPages_onlyNew AS (
		SELECT uid AS '__ORIG_UID__', uid, pid, title, sorting, t3ver_oid, t3ver_wsid, t3ver_state, t3ver_stage, deleted 
        FROM getAllWsPages 
        WHERE t3ver_oid=0 AND (t3ver_state=1 OR t3ver_state=-1)
	),
    -- Step 3: Merge all data parts from step 2
    data AS (
		SELECT * FROM getAllLivePages_exclWsEdit
		UNION
        SELECT * FROM getAllWsPages_exclUnchangedLivePages
        UNION
        SELECT * FROM getAllWsPages_onlyNew
        
	),
    result AS (
		WITH RECURSIVE rcte AS (
			SELECT
				D.__ORIG_UID__,
				D.uid, 
				D.title, 
				D.pid,
				D.sorting,
				D.t3ver_oid,
				D.t3ver_wsid,
				D.t3ver_state,
				D.t3ver_stage,
				1 as '__CTE_LEVEL__',
				CAST(LPAD(D.sorting,10,'0') AS CHAR(200)) as '__CTE_SORTING__',
				CAST(D.uid AS CHAR(100)) as '__CTE_PATH__',
				D.title as '__CTE_TITLE__'
			FROM data D
			WHERE D.pid = @selectedPid AND D.t3ver_state <> 2 -- IF @selectedPid=0 THEN selector=D.pid ELSE selector=D.uid
			UNION ALL
			SELECT
				D.__ORIG_UID__,
				D.uid,
				D.title, 
				D.pid,
				D.sorting,
				D.t3ver_oid,
				D.t3ver_wsid,
				D.t3ver_state,
				D.t3ver_stage,
				R.__CTE_LEVEL__+1,
				CONCAT(R.__CTE_SORTING__,'/',LPAD(D.sorting,10,'0')) as '__CTE_SORTING__',
				CONCAT(R.__CTE_PATH__,'/',D.uid) as '__CTE_PATH__',
				CONCAT(REPEAT('  ', R.__CTE_LEVEL__), D.title) as '__CTE_TITLE__'
			FROM data D
			INNER JOIN rcte R ON R.uid = D.pid
            WHERE D.t3ver_state <> 2 -- Escape condition for recursion (deleted parent page)
				AND R.__CTE_LEVEL__ < @maxTraversalLevel -- Escape condition for maximum traversal level (max. parent pages)
		)
        SELECT * FROM rcte
	)
select * from result ORDER BY __CTE_SORTING__ ASC;