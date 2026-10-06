CREATE TABLE demo.members (
    member_key VARBINARY(32) NOT NULL PRIMARY KEY
);
CREATE USER 'reader'@'%' IDENTIFIED BY 'fixture-reader-only';
GRANT SELECT ON demo.members TO 'reader'@'%';
CREATE USER 'seeder'@'%' IDENTIFIED BY 'fixture-seeder-only';
GRANT SELECT, INSERT ON demo.members TO 'seeder'@'%';
