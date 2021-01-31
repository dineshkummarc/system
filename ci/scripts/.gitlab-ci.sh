stages:
  - init
  - test

variables:
  BILLING_DB: 'billing'
  BILLING_DB_PORT: 27617  
  BRANCH_NAME: $CI_COMMIT_REF_NAME

default:
  before_script:
    - export prefix=`pwd`
    - source $prefix/ci/scripts/gitlab.sh

echo_run:
    stage: test
    tags:
      - CI_DEV
    script:
      - RESET_MONGO_DB $BILLING_DB $BILLING_DB_PORT
      - GET_ACCESS_TOKEN "http://46.101.14.10"
      - echo "$BILL_RUN_ACCESS_TOKEN"
        


