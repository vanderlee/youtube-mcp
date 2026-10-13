CREATE TABLE quota_alerts
(
    window    TEXT    NOT NULL,
    bucket    TEXT    NOT NULL,
    threshold INTEGER NOT NULL,
    PRIMARY KEY (window, bucket, threshold)
);
