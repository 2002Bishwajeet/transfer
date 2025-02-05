# This script uses your .env file and updates the backups.tar for NHost and Supabase.

source ../../.env

echo "Updating Supabase Backup..."
export PGPASSWORD=$SUPABASE_TEST_PASSWORD
pg_dump -U $SUPABASE_TEST_USERNAME -h $SUPABASE_TEST_HOST -p 5432 $SUPABASE_TEST_DATABASE > supabase/backup.tar
unset PGPASSWORD
echo "Done"

echo "Updating NHost Backup..."
export PGPASSWORD=$NHOST_TEST_PASSWORD
pg_dump -U $NHOST_TEST_USERNAME -h $NHOST_TEST_SUBDOMAIN.db.$NHOST_TEST_REGION.nhost.run -p 5432 $NHOST_TEST_DATABASE > nhost/backup.tar
unset PGPASSWORD
echo "Done"
