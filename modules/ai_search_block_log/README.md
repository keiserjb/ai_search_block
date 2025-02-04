# TASKS

## BACKEND After rag call
Create log entry
- query
- uid
- block-id
- expiry
- actual prompt
- data
return log entry with stream

## BACKEND After stream is complete
- Update log with full text response

## Frontend
- Update DOM with LOGID
- Make JS to trigger ajax calls

## FRONTEND Giving feedback
- Trigger ajax call to /ai_search_block/log/{id}/feedback
