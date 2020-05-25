using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Drawing;
using System.Data;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Windows.Forms;
using WebSocketSharp;
using Newtonsoft.Json.Linq;
using System.Net.Http;
using Newtonsoft.Json;

namespace AnyPrinterUserControl
{
    
    public partial class AnyPrinterUserControl: UserControl
    {
        private string m_serverUrl;
        private bool m_connected = false;
        private WebSocket m_webSocket;
        private string m_token;
        private string m_rawImage;
        private const string m_defaultText = "Enter text here...";

        public delegate void voidDelegate();
        public voidDelegate ExecuteCommand1;
        public voidDelegate ExecuteCommand2;
        public voidDelegate ExecuteCommand3;
        public voidDelegate ExecuteCommand4;

        public string ServerUrl
        {
            get { return m_serverUrl; }
            set { m_serverUrl = value; }
        }

        public string PrinterHotHead
        {
            get { return txtHotHead.Text.ToString(); }
            set { txtHotHead.Text = value; }
        }

        public string PrinterHotBed
        {
            get { return txtHotBed.Text.ToString(); }
            set { txtHotBed.Text = value; }
        }

        public string PrinterTime
        {
            get { return txtTime.Text.ToString(); }
            set { txtTime.Text = value; }
        }

        public string PrinterFilament
        {
            get { return txtFilament.Text.ToString(); }
            set { txtFilament.Text = value; }
        }

        public string PrinterName
        {
            get { return txtName.Text.ToString(); }
            set { txtName.Text = value; }
        }

        public string PrinterPassword
        {
            get { return txtPassword.Text.ToString(); }
            set { txtPassword.Text = value; }
        }
        public string PrinterReadOnlyPassword
        {
            get { return txtReadOnlyPassword.Text.ToString(); }
            set { txtReadOnlyPassword.Text = value; }
        }

        public AnyPrinterUserControl()
        {
            InitializeComponent();
            PrinterName = "alex718@gmail.com";
            PrinterPassword = "123456";
            PrinterReadOnlyPassword = "111";
            ServerUrl = "wss://any3dprinter.com/wss";
            //ServerUrl = "ws://localhost:8090";
            m_token = "";
        }

        public bool IsConnected() // get the connection status
        {
            return m_connected;
        }

        void SetConnectionStatus(bool status)
        {
            txtName.Enabled = !status;
            txtPassword.Enabled = !status;
            txtReadOnlyPassword.Enabled = !status;
            btnConnect.Text = status ? "Disconnect" : "Connect";
            m_connected = status;
            if (!status) m_token = "";
        }
        
        public string GenerateStatusJsonString()
        {
            JObject json = new JObject();
            json["sender_type"] = "printer";    // owner type : {printer, user, server}
            json["sender_name"] = PrinterName;  // owner name
            json["msg_type"] = "status";        // message type : {auth, status, command}

            JObject json_property = new JObject();
            json_property["hothead"] = PrinterHotHead;
            json_property["hotbed"] = PrinterHotBed;
            json_property["filament"] = PrinterFilament;
            json_property["time"] = PrinterTime;

            JObject json_msg_content = new JObject();
            json_msg_content["property"] = json_property;
            json_msg_content["token"] = m_token;
            json["msg_content"] = json_msg_content; // message content : json object with various value

            return json.ToString();
        }

        public string GenerateAuthJsonString()
        {
            JObject json = new JObject();
            json["sender_type"] = "printer";    // owner type : {printer, user, server}
            json["sender_name"] = PrinterName;  // owner name
            json["msg_type"] = "auth";          // message type : {auth, status, command}

            JObject json_msg_content = new JObject();
            json_msg_content["password"] = PrinterPassword;
            json_msg_content["ReadOnlyPassword"] = PrinterReadOnlyPassword;

            json["msg_content"] = json_msg_content; // message content : json object with various value

            return json.ToString();
        }

        public bool SendMessageToServer(string msg) // send status to the server
        {
            if (!IsConnected())
            {
                MessageBox.Show("Please connect to the Server first.");
                return false;
            }
            //MessageBox.Show(msg);
            m_webSocket.Send(msg);
            return true;
        }

        private void AnyPrinterUserControl_Load(object sender1, EventArgs e1)
        {
           
        }
        
        private void AddLog(string log)
        {
            listLog.Items.Insert(0, "[" + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "] " + log);
        }

        private void DisconnectFromServer()
        {
            m_webSocket.CloseAsync();
            AddLog("Closing the connections...");
        }

        private void OnServerOpen(object sender, EventArgs e)
        {
            SetConnectionStatus(true);
            if (SendMessageToServer(GenerateAuthJsonString()))
            {
                AddLog("Connected to the Server.");
            }
        }

        private void OnServerMessage(object sender, MessageEventArgs e)
        {
            var json = JObject.Parse(e.Data.ToString());

            if (json["sender_type"].ToString() == "user" && json["msg_type"].ToString() == "command" && json["msg_content"]["printer_name"].ToString() == PrinterName)
            {
                var jsonCommand = json["msg_content"]["command_name"].ToString();

                switch (jsonCommand)
                {
                    case "Command1":
                        ExecuteCommand1?.Invoke();
                        break;
                    case "Command2":
                        ExecuteCommand2?.Invoke();
                        break;
                    case "Command3":
                        ExecuteCommand3?.Invoke();
                        break;
                    case "Command4":
                        ExecuteCommand4?.Invoke();
                        break;
                }

                AddLog("\"" + json["sender_name"].ToString() + "\": " + jsonCommand);
            }
            else if (json["sender_type"].ToString() == "server" && json["msg_type"].ToString() == "token" && json["msg_content"]["printer_name"].ToString() == PrinterName)
            {
                m_token = json["msg_content"]["token"].ToString();

                AddLog("\"" + json["sender_name"].ToString() + "\": " + m_token);
            }
        }

        private void OnServerError(object sender, ErrorEventArgs e)
        {

        }

        private void OnServerClose(object sender, CloseEventArgs e)
        {
            SetConnectionStatus(false);
            AddLog("Disconnected from the Server.");
        }

        private void ConnectToServer(string socket_server_url)
        {
            if(PrinterPassword == PrinterReadOnlyPassword)
            {
                MessageBox.Show("Please input the [ReadOnlyPassword] differently with Password.");
                txtReadOnlyPassword.Focus();
                return;
            }
            m_webSocket = new WebSocket(socket_server_url);

            // Set the WebSocket events.
            m_webSocket.OnOpen += (sender, e) => OnServerOpen(sender, e);

            m_webSocket.OnMessage += (sender, e) => OnServerMessage(sender, e);

            m_webSocket.OnError += (sender, e) => OnServerError(sender, e);

            m_webSocket.OnClose += (sender, e) => OnServerClose(sender, e);

            // Connect to the server.
            //m_webSocket.Connect();

            // Connect to the server asynchronously.
            m_webSocket.ConnectAsync();
        }

        private void BtnConnect_Click(object sender1, EventArgs e1)
        {
            if (IsConnected())
                DisconnectFromServer();
            else
                ConnectToServer(ServerUrl);
        }

        private void BtnSendStatus_Click(object sender, EventArgs e)
        {
            if (m_token.IsNullOrEmpty())
            {
                MessageBox.Show("No token received. Please wait till server send a token.");
                return;
            }
            if (SendMessageToServer(GenerateStatusJsonString()))
            {
                AddLog("Sent a message to the server.");
            }
        }

        private void SendButtonStatus(string btnName, string text, string color)
        {
            if (m_token.IsNullOrEmpty())
            {
                MessageBox.Show("No token received. Please wait till server send a token.");
                return;
            }
            SendMessageToServer(GenerateButtonStatusJsonString(btnName, text, color));
        }

        public string GenerateButtonStatusJsonString(string btnName, string text, string color)
        {
            JObject json = new JObject();
            json["sender_type"] = "printer";    // owner type : {printer, user, server}
            json["sender_name"] = PrinterName;  // owner name
            json["msg_type"] = "status";        // message type : {auth, status, command}

            JObject json_btn_status = new JObject();
            json_btn_status["name"] = btnName;
            json_btn_status["text"] = text;
            json_btn_status["color"] = color;

            JObject json_msg_content = new JObject();
            json_msg_content["button"] = json_btn_status;
            json_msg_content["token"] = m_token;
            json["msg_content"] = json_msg_content; // message content : json object with various value

            return json.ToString();
        }

        public string GeneratePictureStatusJsonString()
        {
            JObject json = new JObject();
            json["sender_type"] = "printer";    // owner type : {printer, user, server}
            json["sender_name"] = PrinterName;  // owner name
            json["msg_type"] = "status";        // message type : {auth, status, command}

            JObject json_msg_content = new JObject();
            json_msg_content["picture"] = "sent";
            json_msg_content["token"] = m_token;
            json["msg_content"] = json_msg_content; // message content : json object with various value

            return json.ToString();
        }

        string colorToRGBA(Color color)
        {
            return color.R.ToString() + "," + color.G.ToString() + "," + color.B.ToString() + "," + color.A.ToString();
        }

        private void btnPictureSend_Click(object sender, EventArgs e)
        {
            if (!IsConnected())
            {
                MessageBox.Show("Please connect to the Server first.");
                return;
            }
            if (txtPicture.Text.IsNullOrEmpty())
            {
                MessageBox.Show("Please select a picture to send.");
                return;
            }
            sendPictureData(txtPicture.Text);
        }

        private async void sendPictureData(string filePath)
        {
            m_rawImage = "";
            readImageToString(filePath);
            if (m_rawImage.IsNullOrEmpty()) return;

            AddLog("Sending Picture Started...");

            HttpClient client = new HttpClient();

            var options = new
            {
                email = PrinterName,
                token = m_token,
                image = m_rawImage
            };

            // Serialize our concrete class into a JSON String
            var stringPayload = JsonConvert.SerializeObject(options);
            var content = new StringContent(stringPayload, Encoding.UTF8, "application/json");
            //var response = await client.PostAsync("http://localhost:8000/upload/image", content);
            var response = await client.PostAsync("https://any3dprinter.com/upload/image", content);
            var responseString = await response.Content.ReadAsStringAsync();

            //MessageBox.Show(responseString);
            //using (System.IO.StreamWriter file =
            //    new System.IO.StreamWriter(@"response.html"))
            //    {
            //        file.Write(responseString);
            //    }

            if (responseString == "Success")
            {
                //SendMessageToServer(GeneratePictureStatusJsonString());
                m_webSocket.SendAsync(GeneratePictureStatusJsonString(), new Action<bool>((result) =>
                {
                    if (result)
                    {
                        AddLog("Sending Picture Ended");
                    }
                }));
            }
        }

        private void readImageToString(string path)
        {
            string dest = "any3dprinter_picture";
            ImageResizer resizer = new ImageResizer(100 * 1024, path, @dest); // Limit the picture size to 100 * 1024 bytes.
            resizer.ScaleImage();
            using (Image image = Image.FromFile(dest))
            {
                using (System.IO.MemoryStream m = new System.IO.MemoryStream())
                {
                    image.Save(m, image.RawFormat);
                    byte[] imageBytes = m.ToArray();

                    // Convert byte[] to Base64 String
                    m_rawImage = Convert.ToBase64String(imageBytes);
                }
            }
        }

        private string getImagePathWithOpenDialog()
        {
            string filePath = "";
            using (OpenFileDialog openFileDialog = new OpenFileDialog())
            {
                openFileDialog.InitialDirectory = "c:\\";
                //openFileDialog.Filter = "txt files (*.txt)|*.txt|All files (*.*)|*.*";
                openFileDialog.Filter = "Image files (*.jpg, *.jpeg, *.jpe, *.jfif, *.png) | *.jpg; *.jpeg; *.jpe; *.jfif; *.png";
                openFileDialog.FilterIndex = 2;
                openFileDialog.RestoreDirectory = true;

                if (openFileDialog.ShowDialog() == DialogResult.OK)
                {
                    //Get the path of specified file
                    filePath = openFileDialog.FileName;
                }
            }
            return filePath;
        }

        private void btnSelectPicture_Click(object sender, EventArgs e)
        {
            txtPicture.Text = getImagePathWithOpenDialog();
        }

        public void RemoveText(object sender, EventArgs e)
        {
            TextBox txtSender = (TextBox)sender;
            if (txtSender.Text == m_defaultText)
            {
                txtSender.Text = "";
            }
        }

        public void AddText(object sender, EventArgs e)
        {
            TextBox txtSender = (TextBox)sender;
            if (string.IsNullOrWhiteSpace(txtSender.Text))
                txtSender.Text = m_defaultText;
        }

        private void labelButton1_Click(object sender, EventArgs e)
        {
            ColorDialog dlg = new ColorDialog();
            if (dlg.ShowDialog() == DialogResult.OK)
            {
                labelButton1.ForeColor = dlg.Color;
            }
        }

        private void labelButton2_Click(object sender, EventArgs e)
        {
            ColorDialog dlg = new ColorDialog();
            if (dlg.ShowDialog() == DialogResult.OK)
            {
                labelButton2.ForeColor = dlg.Color;
            }
        }

        private void labelButton3_Click(object sender, EventArgs e)
        {
            ColorDialog dlg = new ColorDialog();
            if (dlg.ShowDialog() == DialogResult.OK)
            {
                labelButton3.ForeColor = dlg.Color;
            }
        }

        private void labelButton4_Click(object sender, EventArgs e)
        {
            ColorDialog dlg = new ColorDialog();
            if (dlg.ShowDialog() == DialogResult.OK)
            {
                labelButton4.ForeColor = dlg.Color;
            }
        }

        private void btn1Send_Click(object sender, EventArgs e)
        {
            SendButtonStatus("btn1", textButton1.Text, colorToRGBA(labelButton1.ForeColor));
        }

        private void btn2Send_Click(object sender, EventArgs e)
        {
            SendButtonStatus("btn2", textButton2.Text, colorToRGBA(labelButton2.ForeColor));
        }

        private void btn3Send_Click(object sender, EventArgs e)
        {
            SendButtonStatus("btn3", textButton3.Text, colorToRGBA(labelButton3.ForeColor));
        }

        private void btn4Send_Click(object sender, EventArgs e)
        {
            SendButtonStatus("btn4", textButton4.Text, colorToRGBA(labelButton4.ForeColor));
        }
    }
}
