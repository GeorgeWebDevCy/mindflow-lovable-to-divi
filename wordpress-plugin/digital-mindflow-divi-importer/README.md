# Digital MindFlow Divi Importer

WordPress admin plugin for importing the bundled Digital MindFlow Divi 5 layouts without WP-CLI, with dedicated actions for the Services page split, the Blog page and homepage Blog section, two surgical Home page fixes for the About and Process sections, native Divi 5 Portfolio CPT loops on the Home and Portfolio pages, and the related Theme Builder templates.

## Install

1. Upload the plugin ZIP from `Plugins > Add New > Upload Plugin`.
2. Activate the plugin.
3. Open `Tools > DMF Divi Importer`.
4. Run a dry run first if you want to preview the changes.
5. Run the real import.
6. If you want the Home page to gain the Blog section plus the dedicated Blog page and single-post Theme Builder template, click `Apply Blog Page Setup`.
7. If you want the Home and Portfolio pages to use native Divi 5 Portfolio loops, click `Fix Portfolio Loops`.
8. If the About section under the hero is stacking on desktop, click `Apply About Two-Column Fix`.
9. If the Process icons need to sit beside the step numbers, click `Apply Process Icon Alignment Fix`.

## What it imports

- page layouts for the expected slugs
- the dedicated Blog page plus the dynamic `Our Blog` section on Home
- native Divi 5 Portfolio post type loops on the Home and Portfolio pages when you run the fix action
- surgical Home page section rewrites for the About and Process sections
- global Theme Builder header and footer
- Theme Builder body templates for single `portfolio` items and single blog posts
- Divi global colors and variables
- the normal WordPress `primary-menu` navigation

The Portfolio loop fix writes native Divi content into the pages, so the site does not need this plugin active just to render those loops.
The About and Process fix buttons also write the updated Divi section markup directly into the Home page, so those fixes continue working after the plugin is removed.
