before(() => {
  cy.adminLogin()
})

describe('admin settings', () => {
  it(`general settings should be visible after saving API key`, () => {
		cy.visit('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=alma')
		cy.get('input#woocommerce_alma_test_api_key').clear().type('test')
		cy.get('button[type="submit"]').click()
		cy.get('#woocommerce_alma_general_section').should('be.visible')
  })
})
