# Anonymous Confession Web Form that posts to Discord Channel Webhook

**About This Project:** This is a web form in php that sends an anonymous confession to a discord public channel with a webhook and also sends the logs of the confession to a private moderator logging channel.

**This is released for educational/demonstration purposes, please use with caution and secure this web form if you use it for for your project or discord server.**


# Installation/Use 

To use, Replace the Public Channel Webhook and Private Logging Channel Webhook 
```
CONFESSION_WEBHOOK = 'https://discord.com/api/webhooks/xxx/zzz'; // public confessions channel webhook
LOG_WEBHOOK        = 'https://discord.com/api/webhooks/xxx/zzz'; // private logging channel webhook
```

Edit the form and embed information and styles to your preference. 

Then upload the form to a web host that supports PHP 7.4 (or above preferably) with the cURL extension.


## Screenshots

![App Screenshot](https://github.heretic.network/screenshots/confession-write.png)
![App Screenshot](https://github.heretic.network/screenshots/confession-submitted.png)
![App Screenshot](https://github.heretic.network/screenshots/confession-posted.png)
![App Screenshot](https://github.heretic.network/screenshots/confession-logged.png)

