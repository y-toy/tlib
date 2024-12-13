-- Create table to make partition tables.
CREATE TABLE IF NOT EXISTS PARTITION_TABLE (
	TABLE_NAME VARCHAR(255) NOT NULL comment 'Table name',
	PARTITION_TYPE INT NOT NULL comment 'PARTITION type 1: Yearly 2: Monthly',
	LAST_EXCUTED_YEAR INT NOT NULL
);


