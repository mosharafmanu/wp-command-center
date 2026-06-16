WP Command Center Runtime Roadmap

Development Rules

Every step must pass all of the following before moving to the next step:

* Implementation complete
* Unit tests written
* Unit tests passing
* Integration tests passing
* REST acceptance test passing
* MCP acceptance test passing
* Real-site verification completed
* Regression suite passing
* Documentation updated
* Memory updated
* Commit created

Do not continue to the next step until the current step is fully green.

⸻

STEP 89 – MCP Error Surface Hardening

Goal

Replace opaque tool failures with structured AI-readable errors.

Current

Failed to call tool

Target

{
  "isError": true,
  "code": "wpcc_patch_breaks_header",
  "message": "Patch would invalidate plugin header"
}

Requirements

* Preserve JSON-RPC transport failures
* Expose business/runtime failures
* Expose operation-specific error codes
* Make errors explainable by AI agents

Acceptance Tests

* Header-breaking patch
* Invalid rollback
* Missing plugin
* Missing media
* Permission denied patch

Deliverable

STEP-89-MCP-ERROR-SURFACE-HARDENING.md

⸻

STEP 90 – Media Runtime

Goal

Complete WordPress media management through REST and MCP.

Operations

* media_upload
* media_get
* media_list
* media_search
* media_update
* media_delete
* media_replace
* media_set_featured
* media_remove_featured

Metadata

* Alt text
* Caption
* Title
* Description

Acceptance Workflow

Create draft

Upload image

Set featured image

Verify image

Replace image

Remove image

Delete image

Verify deletion

Deliverable

STEP-90-MEDIA-RUNTIME.md

⸻

STEP 91 – SEO Runtime

Goal

Unified SEO management regardless of SEO plugin.

Supported Plugins

* Rank Math
* Yoast SEO

Runtime API

* seo_get
* seo_update
* seo_validate
* seo_analyze

Fields

* SEO title
* Meta description
* Focus keyword
* Canonical URL
* Open Graph
* Twitter Cards
* Robots

Acceptance Workflow

Create content

Generate SEO

Save SEO

Verify metadata

Update SEO

Verify changes

Deliverable

STEP-91-SEO-RUNTIME.md

⸻

STEP 92 – ACF Runtime

Goal

Allow AI agents to build and manage ACF structures.

Operations

* acf_group_create
* acf_group_update
* acf_group_delete
* acf_field_create
* acf_field_update
* acf_field_delete
* acf_layout_create
* acf_layout_update

Supported Fields

* Text
* Textarea
* Image
* Gallery
* Repeater
* Flexible Content
* Relationship
* Select
* Group
* Clone

Acceptance Workflow

Create field group

Add fields

Add repeater

Add flexible content

Attach to CPT

Verify admin UI

Deliverable

STEP-92-ACF-RUNTIME.md

⸻

STEP 93 – WooCommerce Product Runtime

Goal

Complete WooCommerce product management.

Operations

* product_create
* product_update
* product_delete
* product_duplicate
* product_publish
* product_unpublish

Product Data

* Title
* Description
* Short Description
* Images
* Categories
* Tags
* Attributes
* Variations
* Inventory
* Pricing
* SKU

Acceptance Workflow

Create product

Add images

Add attributes

Create variations

Publish

Update inventory

Verify frontend

Deliverable

STEP-93-WOOCOMMERCE-PRODUCT-RUNTIME.md

⸻

STEP 94 – WooCommerce Order Runtime

Goal

Order and customer management.

Operations

* order_get
* order_list
* order_update
* order_note_add
* order_status_change
* refund_create
* customer_get
* customer_search

Acceptance Workflow

Create order

Read order

Add note

Change status

Verify WooCommerce

Deliverable

STEP-94-WOOCOMMERCE-ORDER-RUNTIME.md

⸻

STEP 95 – Site Builder Runtime

Goal

Allow AI agents to construct WordPress sites.

Operations

* page_create
* page_update
* page_delete
* menu_create
* menu_update
* navigation_manage
* pattern_create
* template_assign

Acceptance Workflow

Create page

Create menu

Assign menu

Publish

Verify frontend

Deliverable

STEP-95-SITE-BUILDER-RUNTIME.md

⸻

STEP 96 – Elementor Runtime

Goal

Understand and edit Elementor pages.

Read Operations

* elementor_get_page
* elementor_export_structure
* elementor_list_widgets

Edit Operations

* elementor_update_text
* elementor_update_image
* elementor_update_button

Acceptance Workflow

Read page

Modify heading

Modify image

Verify frontend

Deliverable

STEP-96-ELEMENTOR-RUNTIME.md

⸻

STEP 97 – Workflow Runtime

Goal

Multi-step AI execution plans.

Example

Create Product

Generate Images

Generate SEO

Publish

Verify

Report

Requirements

Single approval

Multiple operations

Execution timeline

Failure recovery

Rollback awareness

Deliverable

STEP-97-WORKFLOW-RUNTIME.md

⸻

STEP 98 – Reporting Runtime

Goal

Generate operational reports.

Reports

* Site Health
* Plugin Health
* Security
* Content
* WooCommerce
* Agent Activity
* Approval Activity
* Patch Activity

Deliverable

STEP-98-REPORTING-RUNTIME.md

⸻

Long-Term Roadmap (Not Now)

Future commercial layer:

* Licensing Runtime
* Billing Runtime
* SaaS Portal
* Activation Server
* Subscription Management

Do not prioritize until core runtimes are complete.