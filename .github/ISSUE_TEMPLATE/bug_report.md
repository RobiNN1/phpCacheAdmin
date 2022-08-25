name: Bug Report
description: "Create a report to help us improve"
title: "Provide a general summary of the issue"
body:
  - type: textarea
    id: issue-description
    attributes:
      label: Describe the issue
      description: "Provide a summary of the issue and what you expected to happen, including specific steps to reproduce."
    validations:
      required: true
  - type: input
    id: pca-version
    attributes:
      label: phpCacheAdmin Version
      placeholder: E.g. v1.0.0
    validations:
      required: true
  - type: input
    id: php-version
    attributes:
      label: PHP Version
      description: "You can find it in the Server tab."
    validations:
      required: true
