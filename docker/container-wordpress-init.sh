if ! $(wp --allow-root core is-installed); then
	wp --allow-root core install --path="/var/www/html" --url="http://localhost:8000" --title="Alma" --admin_user=alma --admin_password=alma --admin_email=alma@alma.alma
	wp --allow-root plugin install woocommerce --activate
	wp --allow-root plugin activate alma-woocommerce-gateway
fi
