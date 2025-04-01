# CHIM Twitch Bot

Server plugin for CHIM. A Twitch chat bot that allows viewers to control AI NPCs in your game through chat commands. Built with PHP and designed to integrate with the CHIM Server.
CHIM can be found here https://www.nexusmods.com/skyrimspecialedition/mods/126330?tab=description

## 🎮 Features

- Real-time Twitch chat integration
- AI NPC control through chat commands
- Simple web-based control panel
- Dark mode interface with Twitch-inspired design
- Easy-to-use command system

## 🛠️ Setup

1. Copy paste files into /ext/twitch-bot of the CHIM Server.
2. Enter in keys.
3. Click Start Bot


## 🎯 Available Commands

### Rolemaster
```
Rolemaster: (Enter request here)
```
This command allows viewers to control AI NPCs in the vicinity. The NPCs will attempt to follow the given commands to the best of their ability.

**Example:**
```
Rolemaster: Make Mikael tell a story
```

## 🔧 Configuration

The bot can be configured through the web interface:
1. Enter your Twitch username
2. Generate and enter your OAuth token
3. Specify your Twitch channel
4. Start/Stop the bot as needed

## 📝 Logs

The bot maintains a log file (`bot_output.log`) that shows the last 25 lines of activity. This can be viewed through the web interface.


## 🤝 Contributing

Feel free to submit issues and enhancement requests!

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details. 