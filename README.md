Google Analytics Counter 8.x-1.0-alpha1
---------------------------------------

### About this Module

Google Analytics Counter is a scalable, lightweight page view counter drawing
on data collected by Google Analytics.

The primary use for this module is to:

- Add a block which contains the count of pageviews from Google Analytics API.

The secondary use for this module is to:

- Use the data Drupal is collecting from Google Analytics API for other things, like search.

### Goals

- A themable block in Drupal which displays Google Analytics Pageviews.
- A comprehensive and understandable solution to using Google Analytics Pageviews in Drupal.
- Google Analytics Counter data is available in views. (to come)

### Installing the Google Analytics Counter Module

1. Copy/upload the google analytics counter module to the modules directory of your Drupal
   installation.

2. Enable the 'Google Analytics Counter' module in 'Extend'.
   (/admin/modules)

3. Set up user permissions. (/admin/people/permissions#module-google_analytics_counter)

4. Go to the settings page. (/admin/config/system/google-analytics-counter)

5. Add your Google Project Client ID, Client Secret and Authorized Redirect URI
   to the Initial Setup section at the bottom of the page. (See "Creating a Project
   in Google" section in the next section of this document). Read and change other
   settings as required.
   Click Save configuration.

6. Go to the Authentication page. (/admin/config/system/google-analytics-counter/authentication)
   Click Set up authentication.

7. Select the google account to which you would like to authenticate.

8. Fill in credentials if requested by Google.
   Click Allow

9. If you did not Prefill a Google View (Profile) ID on the Settings form, go back
   to /admin/config/system/google-analytics-counter and Select a view (profile)
   from the select list under Google Views (Profiles) IDS.
   Click Save configuration

10. Go to the dashboard (/admin/config/system/google-analytics-counter/dashboard)

11. Note most of the numbers are 0 until you run cron

12. Generally speaking, it is a good idea to run cron continuously with a
    scheduler like Jenkins to keep pageviews data up to date.

13. Place a Google Analytics Counter block on your site.
   (/admin/structure/block)

### Project Status

- [Port google analytics counter module to drupal 8](https://www.drupal.org/project/google_analytics_counter/issues/2695915)
Author: Tomas Fulopp (Vacilando) for Drupal 7, Eric Sod (esod) for Drupal 8.

### Creating a Project in Google

1. Go to https://console.developers.google.com/cloud-resource-manager?previousPage=%2F
   Click Create project

2. Name your project
   Click Create. Wait several moments for your project to be created.

3. Go to https://console.developers.google.com/apis/dashboard
   You will most likely be directed to your project, or select your project by
   clicking in the upper left corner next to Google APIS and selecting your
   project from the pop up.

4. Click Enable APIS and services on the Google APIs dashboard.
   Search for Analytics API.
   Click Analytics API.
   On the proceeding page, click Enable.

5. You are sent back on the Google APIs page, click Credentials in the left-hand column.

6. Click Create credentials. Select OAUTH client ID.

7. Click Configure consent screen.
   Fill out the OAuth consent screen form.
   Click Save.

8. You are sent back to the page where you can select your Application type.
   Select Web application

9. Name it in the Name field

10. Leave the Authorized JavaScript origins field blank.

11. Add a url to the Authorized redirect URIs
    Example: http://localhost/d8/admin/config/system/google-analytics-counter/authentication
    Click Create

12. Note your client ID and client secret
    You may also get your client ID and client secret by clicking the pencil icon
    on the right side of the Credentials page next to your application name.
