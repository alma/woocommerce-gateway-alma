Cypress.Commands.add('adminLogin', () => {
  cy.request({
		method: 'POST',
		url: '/wp-login.php',
		form: true,
		followRedirect: false,
		body: { log: "alma", pwd: "alma" },
	})
})
