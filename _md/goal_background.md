# FIA Business Solution Migration

## Background
I am a retired FileMaker developer and I have a client "Florida Inspection Associates" (FIA) of over 20 years that has a business system that is getting very old (outdated) and becoming costly to maintain due to licensing and plugin costs. The goal is to update the codebase and database backend to alleviate costs and allow for easier maintenance for future development.

FIA is an Automotive Inspection service company that hires contractors all over the country to perform mechanical inspections, primarily at service garages. The inspections are requested by insurance companies that want a 3rd-party to investigate potential repairs needed vs warranty coverage.

## Workflow
1. Insurance companies request inspection via email template or pulled from API
2. Office staff dispatch inspection requests to inspectors by proximity to service garage. (FileMaker)
3. Inspectors (contractors) log into their account and access their assigned inspections via web browser.
4. Inspectors report their findings back into the inspection record online, including the upload of pictures and videos for evidence.
5. Office staff create PDF reports, consisting of data and photos, and email to insurance agent. (FileMaker)
6. Office staff bills the Insurance Company for inspections completed that day via Quickbooks.  

## Notes
- Office staff work in FileMaker on front-end.
- FileMaker database originally developed in 2005 (21+ years old).
- Finding FileMAker developers, especially as freelancers, is getting increasingly hard to find.
- Software licensing for FileMaker and required plugins is relatively expensive, including proprietary server hosting. 

## Goals
- Elliminate the need for FileMaker software entirely.
- - Migrate FileMaker data across all tables to MySql.
- - Replace FileMaker front-end data-entry and scripting with web-based forms.
- - Allow for hosting without server software required.
- - There is a lot of bloat in the FileMaker scripting, and what is valid (to be determined) needs to be replaced with PHP equivalents.
- Keep codebase in PHP (without a formal framework) to maintain continuity with current development and keep maintenance minimal.

#### Important
Before we get into the nuts and bolts of doing the actual code and data migration, we will need to present a formal proposal for costs and scope of this project. We should list all of the most obvious advantages of doing this migration in the first place.