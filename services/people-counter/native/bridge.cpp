#include <winsock2.h>
#include <windows.h>
#include <QCoreApplication>
#include <QApplication>
#include <QWidget>
#include <QFile>
#include <QJsonArray>
#include <QJsonDocument>
#include <QJsonObject>
#include <QMutex>
#include <QMutexLocker>
#include <QRegularExpression>
#include <QTimer>
#include <atomic>
#include <chrono>
#include <cstdio>
#include <iostream>
#include "lcopensdk_api/LCOpenSDK_Api.h"
#include "lcopensdk_loginmanager/LCOpenSDK_LoginManager.h"
#include "lcopensdk_utils/LCOpenSDK_Utils.h"
#include "lcopensdk_videoplay/LCOpenSDK_VideoPlay.h"

namespace {
std::atomic<bool> stopping(false);

BOOL WINAPI handleConsole(DWORD signal)
{
    if (signal == CTRL_C_EVENT || signal == CTRL_BREAK_EVENT || signal == CTRL_CLOSE_EVENT) {
        stopping = true;
        return TRUE;
    }
    return FALSE;
}

void status(const char* value)
{
    std::cout << "{\"state\":\"" << value << "\"}" << std::endl;
}

class Listener : public LCOpenSDK_PlayerListener {
public:
    SOCKET output = INVALID_SOCKET;
    sockaddr_in destination = {};
    std::atomic<unsigned long long> bytes{0};
    std::atomic<bool> began{false};
    QMutex transport;

    void onPlayerResult(int, const char*, int) override {}
    void onResolutionChanged(int, int, int) override {}
    void onPlayBegan(int) override { began = true; }
    void onReceiveData(int, int) override {}
    void onPlayFinished(int) override { began = false; }
    void onPlayerTime(int, long) override {}
    void onIVSInfo(int, char*, long, long, long) override {}
    void onAssistFrameInfo(int, const char*, unsigned int) override {}

    void onStreamCallback(int, const unsigned char* data, unsigned int length) override
    {
        if (!data || length == 0 || length > 4 * 1024 * 1024 || output == INVALID_SOCKET) {
            return;
        }
        QMutexLocker lock(&transport);
        bytes += length;
        for (unsigned int offset = 0; offset < length; offset += 1316) {
            int size = static_cast<int>((length - offset) > 1316 ? 1316 : length - offset);
            sendto(output, reinterpret_cast<const char*>(data + offset), size, 0,
                   reinterpret_cast<const sockaddr*>(&destination), sizeof(destination));
        }
    }
};
}

int main(int argc, char** argv)
{
    QApplication app(argc, argv);
    app.setQuitOnLastWindowClosed(false);
    if (app.arguments().contains("--check")) {
        // Exercise the callback lock against the shipped runtime, not only DLL loading.
        Listener listener;
        QMutexLocker lock(&listener.transport);
        status("READY_WINDOWS_X64");
        return 0;
    }
    QFile input;
    if (!input.open(stdin, QIODevice::ReadOnly)) {
        status("INPUT_UNAVAILABLE");
        return 2;
    }
    QByteArray encoded = input.read(32769);
    QJsonParseError error;
    QJsonDocument document = QJsonDocument::fromJson(encoded, &error);
    if (encoded.size() > 32768 || error.error != QJsonParseError::NoError || !document.isObject()) {
        status("INVALID_INPUT");
        return 2;
    }
    QJsonObject session = document.object();
    QString host = session.value("host").toString();
    if (host != "openapi-sg.easy4ip.com" && host != "openapi-fk.easy4ip.com"
        && host != "openapi-or.easy4ip.com") {
        status("INVALID_REGION");
        return 2;
    }
    for (const char* name : {"appId", "appSecret", "token", "deviceId", "psk"}) {
        QString value = session.value(name).toString();
        if (value.isEmpty() || value.size() > 4096) {
            status("INVALID_SESSION");
            return 2;
        }
    }
    if (!QRegularExpression("^[A-Za-z0-9_-]{1,64}$").match(session["deviceId"].toString()).hasMatch()) {
        status("INVALID_DEVICE");
        return 2;
    }
    int duration = session.value("duration").toInt(60);
    int port = session.value("udpPort").toInt(19456);
    int channel = session.value("channel").toInt(-1);
    QString stopFile = session.value("stopFile").toString();
    if (duration < 1 || duration > 3600 || port < 1024 || port > 65535 || channel < 0 || channel > 255) {
        status("INVALID_LIMITS");
        return 2;
    }
    QByteArray token = session["token"].toString().toUtf8();
    QByteArray appId = session["appId"].toString().toUtf8();
    QByteArray secret = session["appSecret"].toString().toUtf8();
    QByteArray device = session["deviceId"].toString().toUtf8();
    QByteArray product = session["productId"].toString().toUtf8();
    QByteArray playToken = session["playToken"].toString().toUtf8();
    QByteArray key = session["psk"].toString().toUtf8();
    WSADATA network;
    if (WSAStartup(MAKEWORD(2, 2), &network) != 0) {
        status("NETWORK_INIT_FAILED");
        return 3;
    }
    Listener listener;
    listener.output = socket(AF_INET, SOCK_DGRAM, IPPROTO_UDP);
    if (listener.output == INVALID_SOCKET) {
        WSACleanup();
        return 3;
    }
    u_long nonBlocking = 1;
    ioctlsocket(listener.output, FIONBIO, &nonBlocking);
    int bufferSize = 4 * 1024 * 1024;
    setsockopt(listener.output, SOL_SOCKET, SO_SNDBUF,
               reinterpret_cast<const char*>(&bufferSize), sizeof(bufferSize));
    listener.destination.sin_family = AF_INET;
    listener.destination.sin_port = htons(static_cast<unsigned short>(port));
    listener.destination.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    SetConsoleCtrlHandler(handleConsole, TRUE);
    QWidget renderingSurface;
    renderingSurface.setAttribute(Qt::WA_DontShowOnScreen);
    renderingSurface.resize(640, 360);
    HWND window = reinterpret_cast<HWND>(renderingSurface.winId());
    // SDK rendering uses an owned hidden HWND; no demo or visible app is needed.
    QByteArray log = (QCoreApplication::applicationDirPath() + "/sdk-native-private").toUtf8();
    LCOpenSdk_Utils::SetLogInfo(LCOpenSdk_Utils::Fatal, log.constData(), 1048576, LCOpenSdk_Utils::RollFile);
    LCOpenSDK_Api* api = LCOpenSDK_Api::GetInstance();
    api->initOpenApiEx(host.toUtf8().constData(), 443, nullptr,
                       token.constData(), appId.constData(), secret.constData());
    QJsonObject item{{"Sn", QString::fromUtf8(device)}, {"PlayToken", QString::fromUtf8(playToken)}};
    QByteArray devices = QJsonDocument(QJsonArray{item}).toJson(QJsonDocument::Compact);
    LCOpenSDK_LoginManager::getInstance()->addDevices(token.constData(), devices.constData());
    api->loginStreamServers(token.constData(), devices.constData());
    LCOpenSDK_LoginManager::getInstance()->getP2pPort(
        token.constData(), playToken.constData(), device.constData(), 5000);
    LCOpenSDK_VideoPlay player;
    if (player.initVideoPlay(0, window) != 0) {
        status("PLAYER_INIT_FAILED");
        return 3;
    }
    player.setPlayerListener(&listener);
    player.setStreamCallback(LCOpenSDK_VideoPlay::OUTPUT_STREAM_FORMAT_TS);
    LCOpenSDK_ParamReal params;
    params.accessToken = token.constData();
    params.deviceID = device.constData();
    params.pid = product.constData();
    params.playToken = playToken.constData();
    params.psk = key.constData();
    params.channel = channel;
    params.defiMode = STREAM_MAIN;
    params.isTls = session.value("tls").toBool();
    if (player.playRtspRealEx(&params) != 0) {
        status("PLAY_REJECTED");
        player.uninitVideoPlay();
        return 3;
    }
    status("CONNECTING");
    QTimer ticker;
    auto started = std::chrono::steady_clock::now();
    QObject::connect(&ticker, &QTimer::timeout, [&]() {
        std::cout << "{\"state\":\"" << (listener.began ? "PLAYING" : "CONNECTING")
                  << "\",\"bytes\":" << listener.bytes.load() << "}" << std::endl;
        if (stopping || (!stopFile.isEmpty() && QFile::exists(stopFile))
            || std::chrono::steady_clock::now() - started >= std::chrono::seconds(duration)) {
            app.quit();
        }
    });
    ticker.start(5000);
    app.exec();
    player.stopRtspReal();
    player.uninitVideoPlay();
    api->uninitOpenApi();
    closesocket(listener.output);
    WSACleanup();
    status("STOPPED");
    return listener.bytes > 0 ? 0 : 4;
}
